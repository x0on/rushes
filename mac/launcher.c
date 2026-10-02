// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
// The front door of Rushes Helper and Rushes Watcher: one source, two apps,
// and the app's name is this program's own name.
//
// macOS decides who may open network drives and other disks by asking which
// APP is responsible for a process. A Python script has no app, so it shows
// up as "python3" — and in System Settings someone has to find and allow a
// program they have never heard of. This tiny program is that app: it starts
// the Python that ships inside the app as its own child, so what macOS sees,
// lists and asks about is the app, with its icon.
//
// It stays running for as long as the child does (never exec: the child
// must keep this app as the responsible one), passes on stop signals, and
// exits with the child's status so launchd can restart it.
//
// Started by launchd (--service): runs the work, and shows the app's icon in
// the menu bar for as long as it runs. No icon, nothing running: if this Mac
// cannot show the icon, the work is stopped (HOW-IT-WORKS.md → Always in
// sight). The menu is drawn from what a second Python child, on this computer
// only (127.0.0.1, with a random key), says the state is; a choice in it is
// sent back to that child, which does it.
// Opened from Finder: shows the app's one window — setup the first time, then
// what it is doing and its switches. The window is this app's own (its name
// in the menu bar, its icon in the Dock) and shows a page the Python child
// serves on this computer only, so one program does the work and the page.
#include <limits.h>
#include <mach-o/dyld.h>
#include <signal.h>
#include <spawn.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <sys/wait.h>
#include <unistd.h>
#include <errno.h>
#include <dlfcn.h>
#include <pthread.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>

#if defined(__aarch64__)
#define ARCH "arm64"
#else
#define ARCH "x86_64"
#endif

extern char **environ;
extern void arc4random_buf(void *, size_t);
static pid_t child = 0, menu_child = 0;
static char NAME[128] = "Rushes", APPDIR[PATH_MAX], PY[PATH_MAX], SCRIPT[PATH_MAX];
static void pass_on(int sig) { if (child > 0) kill(child, sig); if (menu_child > 0) kill(menu_child, sig); }
static void stop_child(void) { if (child > 0) kill(child, SIGTERM); if (menu_child > 0) kill(menu_child, SIGTERM); }

// ── the Objective-C runtime, looked up at run time (no SDK needed to build) ──
typedef void *id; typedef void *SEL; typedef void *Class;
typedef struct { double x, y, w, h; } Rect;
static id (*getcls)(const char *); static SEL (*sel)(const char *); static void *msg;
static Class (*newcls)(Class, const char *, size_t); static void (*regcls)(Class);
static signed char (*addm)(Class, SEL, void *, const char *);
static void *(*pool_push)(void); static void (*pool_pop)(void *);
static id C(const char *n) { return getcls(n); }
static id m0(id o, const char *s) { return ((id (*)(id, SEL))msg)(o, sel(s)); }
static id m1(id o, const char *s, id a) { return ((id (*)(id, SEL, id))msg)(o, sel(s), a); }
static void mb(id o, const char *s, signed char b) { ((void (*)(id, SEL, signed char))msg)(o, sel(s), b); }
static id str(const char *s) { return ((id (*)(id, SEL, const char *))msg)(C("NSString"), sel("stringWithUTF8String:"), s); }
static const char *utf8(id s) { const char *u = s ? ((const char *(*)(id, SEL))msg)(s, sel("UTF8String")) : NULL; return u ? u : ""; }
static id item(const char *title, const char *action, const char *key) {
    return ((id (*)(id, SEL, id, SEL, id))msg)(m0(C("NSMenuItem"), "alloc"),
        sel("initWithTitle:action:keyEquivalent:"), str(title), action ? sel(action) : NULL, str(key));
}
static signed char yes(id self, SEL cmd, id a) { (void)self; (void)cmd; (void)a; return 1; }

static int objc_up(void) {
    void *objc = dlopen("/usr/lib/libobjc.A.dylib", RTLD_NOW);
    if (!objc || !dlopen("/System/Library/Frameworks/AppKit.framework/AppKit", RTLD_NOW)) return 0;
    getcls = dlsym(objc, "objc_getClass"); sel = dlsym(objc, "sel_registerName"); msg = dlsym(objc, "objc_msgSend");
    newcls = dlsym(objc, "objc_allocateClassPair"); regcls = dlsym(objc, "objc_registerClassPair");
    addm = dlsym(objc, "class_addMethod");
    pool_push = dlsym(objc, "objc_autoreleasePoolPush"); pool_pop = dlsym(objc, "objc_autoreleasePoolPop");
    return getcls && sel && msg && newcls && regcls && addm && pool_push && pool_pop && C("NSApplication");
}

static id app_;
static void *watch(void *unused) {
    (void)unused;
    int st; while (waitpid(child, &st, 0) < 0 && errno == EINTR) {}
    child = 0;       // the page said Done (or the child stopped): close the app
    ((void (*)(id, SEL, SEL, id, signed char))msg)(app_, sel("performSelectorOnMainThread:withObject:waitUntilDone:"),
        sel("terminate:"), NULL, 0);
    return NULL;
}

// A window with the page in it. 0 when this Mac cannot show one (then the
// page opens in the browser instead).
static int window(const char *addr) {
    if (!objc_up() || !dlopen("/System/Library/Frameworks/WebKit.framework/WebKit", RTLD_NOW) || !C("WKWebView")) return 0;
    char quit[160]; snprintf(quit, sizeof quit, "Quit %s", NAME);

    id app = app_ = m0(C("NSApplication"), "sharedApplication");
    ((void (*)(id, SEL, long))msg)(app, sel("setActivationPolicy:"), 0);   // a Dock icon while the window is open

    // Closing the window quits the app (and the page's Python with it).
    Class d = newcls(C("NSObject"), "RushesWindowDelegate", 0);
    addm(d, sel("applicationShouldTerminateAfterLastWindowClosed:"), (void *)yes, "c@:@");
    regcls(d);
    m1(app, "setDelegate:", m0(m0(d, "alloc"), "init"));

    // The menus a Mac app is expected to have: Quit, and Edit so that paste
    // (the Rushes address) works in the page.
    id bar = m0(m0(C("NSMenu"), "alloc"), "init"), top, menu;
    top = item("", NULL, ""); m1(bar, "addItem:", top);
    menu = m0(m0(C("NSMenu"), "alloc"), "init");
    m1(menu, "addItem:", item(quit, "terminate:", "q"));
    m1(top, "setSubmenu:", menu);
    top = item("Edit", NULL, ""); m1(bar, "addItem:", top);
    menu = m1(m0(C("NSMenu"), "alloc"), "initWithTitle:", str("Edit"));
    m1(menu, "addItem:", item("Undo", "undo:", "z"));
    m1(menu, "addItem:", item("Cut", "cut:", "x"));
    m1(menu, "addItem:", item("Copy", "copy:", "c"));
    m1(menu, "addItem:", item("Paste", "paste:", "v"));
    m1(menu, "addItem:", item("Select All", "selectAll:", "a"));
    m1(top, "setSubmenu:", menu);
    m1(app, "setMainMenu:", bar);

    Rect r = {0, 0, 880, 620};
    id win = ((id (*)(id, SEL, Rect, unsigned long, unsigned long, signed char))msg)(m0(C("NSWindow"), "alloc"),
        sel("initWithContentRect:styleMask:backing:defer:"), r, 1 | 2 | 4, 2, 0);   // titled, closable, minimizable
    mb(win, "setReleasedWhenClosed:", 0);
    m1(win, "setTitle:", str(NAME));
    id web = ((id (*)(id, SEL, Rect, id))msg)(m0(C("WKWebView"), "alloc"), sel("initWithFrame:configuration:"), r,
        m0(m0(C("WKWebViewConfiguration"), "alloc"), "init"));
    m1(win, "setContentView:", web);
    m1(web, "loadRequest:", m1(C("NSURLRequest"), "requestWithURL:", m1(C("NSURL"), "URLWithString:", str(addr))));
    m0(win, "center");
    m1(win, "makeKeyAndOrderFront:", NULL);
    mb(app, "activateIgnoringOtherApps:", 1);

    pthread_t t; pthread_create(&t, NULL, watch, NULL);
    m0(app, "run");                    // until the window closes or the page says Done
    return 1;
}

// ── the menu bar icon, while the work runs ──────────────────────────────────
// The menu child answers GET <base>menu with
//   {"icon": "<SF Symbol name>", "tip": "…", "items": [{"label": "…", "do": "<id>", "on": true|false} | {"sep": true}]}
// (an item without "do" is a line of information), and GET <base>do?a=<id> by doing it.
static id target_, status_, menu_;
static char base_[96];
static pthread_mutex_t mu = PTHREAD_MUTEX_INITIALIZER;
static pthread_cond_t cv = PTHREAD_COND_INITIALIZER;
static int asked = 0;

static void wake(void) { pthread_mutex_lock(&mu); asked = 1; pthread_cond_signal(&cv); pthread_mutex_unlock(&mu); }

static id get(const char *path) {       // a question to the menu child; nil when it does not answer
    char u[512]; snprintf(u, sizeof u, "%s%s", base_, path);
    id url = m1(C("NSURL"), "URLWithString:", str(u));
    return url ? m1(C("NSData"), "dataWithContentsOfURL:", url) : NULL;
}

// What the menu says: every 3 seconds, at once after a choice, and fresh when it is opened.
static void *fetcher(void *unused) {
    (void)unused;
    for (int fresh = 1;;) {
        void *p = pool_push();
        id data = get(fresh ? "menu?fresh=1" : "menu"), obj = NULL;
        if (data) obj = ((id (*)(id, SEL, id, unsigned long, id *))msg)(C("NSJSONSerialization"),
                        sel("JSONObjectWithData:options:error:"), data, 0, NULL);
        ((void (*)(id, SEL, SEL, id, signed char))msg)(target_, sel("performSelectorOnMainThread:withObject:waitUntilDone:"),
            sel("refresh:"), obj, 0);
        pool_pop(p);
        pthread_mutex_lock(&mu);
        if (!asked) {
            struct timespec ts; clock_gettime(CLOCK_REALTIME, &ts); ts.tv_sec += 3;
            pthread_cond_timedwait(&cv, &mu, &ts);
        }
        fresh = asked; asked = 0;
        pthread_mutex_unlock(&mu);
    }
    return NULL;
}

static void *send_choice(void *a) {
    char path[160]; snprintf(path, sizeof path, "do?a=%s", (char *)a); free(a);
    void *p = pool_push(); get(path); pool_pop(p);
    wake();
    return NULL;
}

static void pick(id self, SEL cmd, id sender) {
    (void)self; (void)cmd;
    const char *a = utf8(m0(sender, "representedObject"));
    char *copy = malloc(64); size_t n = 0;
    for (; a[n] && n < 63; n++) copy[n] = (a[n] >= 'a' && a[n] <= 'z') || a[n] == '-' ? a[n] : '-';   // plain ids only
    copy[n] = 0;
    pthread_t t; pthread_create(&t, NULL, send_choice, copy); pthread_detach(t);
}

static void opened(id self, SEL cmd, id m) { (void)self; (void)cmd; (void)m; wake(); }

// The Rushes mark (MenuIcon.pdf inside the app), drawn by macOS in the menu
// bar's own colour. The state is shown on it: dimmed when paused or cut off
// from Rushes, a "!" beside it when it needs someone.
static id logo_;
typedef struct { double w, h; } Size;
static void refresh(id self, SEL cmd, id d) {
    (void)self; (void)cmd;
    id button = m0(status_, "button");
    const char *st = d ? utf8(m1(d, "objectForKey:", str("state"))) : "offline";
    if (!logo_ && (logo_ = m1(m0(C("NSBundle"), "mainBundle"), "imageForResource:", str("MenuIcon")))) {
        m0(logo_, "retain"); mb(logo_, "setTemplate:", 1);
        ((void (*)(id, SEL, Size))msg)(logo_, sel("setSize:"), (Size){18, 18});
    }
    if (logo_) {
        m1(button, "setImage:", logo_);
        m1(button, "setTitle:", str(strcmp(st, "attention") == 0 || !d ? "!" : ""));
        ((void (*)(id, SEL, long))msg)(button, sel("setImagePosition:"), 2);          // the mark, then the "!"
        mb(button, "setAppearsDisabled:", strcmp(st, "paused") == 0 || strcmp(st, "offline") == 0);
    } else {                                // no mark inside the app: the state as a symbol
        id icon = d ? m1(d, "objectForKey:", str("icon")) : NULL, img = NULL;
        if (!icon) icon = str("exclamationmark.triangle");
        if (((signed char (*)(id, SEL, SEL))msg)(C("NSImage"), sel("respondsToSelector:"), sel("imageWithSystemSymbolName:accessibilityDescription:")))
            img = ((id (*)(id, SEL, id, id))msg)(C("NSImage"), sel("imageWithSystemSymbolName:accessibilityDescription:"), icon, str(NAME));
        if (img) { mb(img, "setTemplate:", 1); m1(button, "setImage:", img); m1(button, "setTitle:", str("")); }
        else m1(button, "setTitle:", str("R"));
    }
    id tip = d ? m1(d, "objectForKey:", str("tip")) : NULL;
    char wait[200]; snprintf(wait, sizeof wait, "%s — starting, or not answering", NAME);
    m1(button, "setToolTip:", tip ? tip : str(wait));

    m0(menu_, "removeAllItems");
    id items = d ? m1(d, "objectForKey:", str("items")) : NULL;
    unsigned long n = items ? ((unsigned long (*)(id, SEL))msg)(items, sel("count")) : 0;
    if (!n) {                          // the menu child is not answering: say so, and let it be quit
        id i = item(wait, NULL, ""); mb(i, "setEnabled:", 0); m1(menu_, "addItem:", i);
        m1(menu_, "addItem:", m0(C("NSMenuItem"), "separatorItem"));
        char quit[160]; snprintf(quit, sizeof quit, "Quit %s", NAME);
        m1(menu_, "addItem:", item(quit, "terminate:", ""));
        return;
    }
    for (unsigned long k = 0; k < n; k++) {
        id it = ((id (*)(id, SEL, unsigned long))msg)(items, sel("objectAtIndex:"), k);
        if (m1(it, "objectForKey:", str("sep"))) { m1(menu_, "addItem:", m0(C("NSMenuItem"), "separatorItem")); continue; }
        id doo = m1(it, "objectForKey:", str("do")), on = m1(it, "objectForKey:", str("on"));
        id mi = item(utf8(m1(it, "objectForKey:", str("label"))), doo ? "pick:" : NULL, "");
        if (doo) { m1(mi, "setTarget:", target_); m1(mi, "setRepresentedObject:", doo); }
        mb(mi, "setEnabled:", doo ? 1 : 0);
        if (on) ((void (*)(id, SEL, long))msg)(mi, sel("setState:"), ((signed char (*)(id, SEL))msg)(on, sel("boolValue")) ? 1 : 0);
        m1(menu_, "addItem:", mi);
    }
}

// Opened again from Finder while it runs: the window, as an instance of its own.
static signed char reopen(id self, SEL cmd, id a, signed char v) {
    (void)self; (void)cmd; (void)a; (void)v;
    char *args[] = {"/usr/bin/open", "-n", APPDIR, NULL}; pid_t o;
    posix_spawn(&o, "/usr/bin/open", NULL, NULL, args, environ);
    return 0;
}

static char port_s[16] = "", key[33] = "";
static void start_menu_child(void) {
    char *a[] = {PY, "-u", SCRIPT, "--menu", port_s, key, NULL};
    if (child > 0) posix_spawn(&menu_child, PY, NULL, NULL, a, environ);
}

static void *watch_both(void *unused) {
    (void)unused;
    for (;;) {
        int st; pid_t p = waitpid(-1, &st, 0);
        if (p < 0) { if (errno == EINTR) continue; sleep(5); continue; }
        if (p == child) {             // the work stopped: so does the icon, with its status for launchd
            child = 0;
            if (menu_child > 0) kill(menu_child, SIGTERM);
            exit(WIFEXITED(st) ? WEXITSTATUS(st) : 128 + WTERMSIG(st));
        }
        if (p == menu_child) { menu_child = 0; sleep(5); start_menu_child(); }   // the menu's own child: started again
    }
    return NULL;
}

static int menubar(void) {
    if (!objc_up() || !C("NSStatusBar")) return 0;
    id app = app_ = m0(C("NSApplication"), "sharedApplication");
    ((void (*)(id, SEL, long))msg)(app, sel("setActivationPolicy:"), 1);   // accessory: the icon, no Dock icon

    Class t = newcls(C("NSObject"), "RushesMenu", 0);
    addm(t, sel("pick:"), (void *)pick, "v@:@");
    addm(t, sel("refresh:"), (void *)refresh, "v@:@");
    addm(t, sel("menuWillOpen:"), (void *)opened, "v@:@");
    addm(t, sel("applicationShouldHandleReopen:hasVisibleWindows:"), (void *)reopen, "c@:@c");
    regcls(t);
    target_ = m0(m0(t, "alloc"), "init");
    m1(app, "setDelegate:", target_);

    status_ = ((id (*)(id, SEL, double))msg)(m0(C("NSStatusBar"), "systemStatusBar"), sel("statusItemWithLength:"), -1.0);
    if (!status_ || !m0(status_, "button")) return 0;
    m0(status_, "retain");
    menu_ = m0(m0(C("NSMenu"), "alloc"), "init");
    mb(menu_, "setAutoenablesItems:", 0);
    m1(menu_, "setDelegate:", target_);
    m1(status_, "setMenu:", menu_);
    refresh(NULL, NULL, NULL);

    start_menu_child();
    snprintf(base_, sizeof base_, "http://127.0.0.1:%s/%s/", port_s, key);
    pthread_t a, b;
    pthread_create(&a, NULL, fetcher, NULL); pthread_create(&b, NULL, watch_both, NULL);
    m0(app, "run");
    return 1;
}

// A free port on this computer only, for the page.
static int free_port(void) {
    int s = socket(AF_INET, SOCK_STREAM, 0), port = 0;
    struct sockaddr_in a; socklen_t n = sizeof a;
    memset(&a, 0, sizeof a);
    a.sin_family = AF_INET; a.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    if (s >= 0 && bind(s, (struct sockaddr *)&a, sizeof a) == 0 && getsockname(s, (struct sockaddr *)&a, &n) == 0)
        port = ntohs(a.sin_port);
    if (s >= 0) close(s);
    return port;
}

static int answers(int port) {
    int s = socket(AF_INET, SOCK_STREAM, 0), ok;
    struct sockaddr_in a;
    memset(&a, 0, sizeof a);
    a.sin_family = AF_INET; a.sin_port = htons(port); a.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
    ok = s >= 0 && connect(s, (struct sockaddr *)&a, sizeof a) == 0;
    if (s >= 0) close(s);
    return ok;
}

int main(int argc, char **argv) {
    char exe[PATH_MAX], real[PATH_MAX];
    uint32_t n = sizeof exe;
    if (_NSGetExecutablePath(exe, &n) != 0 || !realpath(exe, real)) {
        fprintf(stderr, "Rushes: cannot tell where it is installed\n");
        return 1;
    }
    // real = <app>/Contents/MacOS/<name>: the app's name, then cut back to <app>
    snprintf(NAME, sizeof NAME, "%s", strrchr(real, '/') + 1);
    for (int i = 0; i < 3; i++) { char *s = strrchr(real, '/'); if (!s) return 1; *s = 0; }
    snprintf(APPDIR, sizeof APPDIR, "%s", real);
    snprintf(PY, sizeof PY, "%s/Contents/Resources/python-%s/bin/python3", real, ARCH);
    snprintf(SCRIPT, sizeof SCRIPT, "%s/Contents/Resources/rushes_helper.py", real);

    // The app stays exactly as it was signed: nothing is written inside it.
    setenv("RUSHES_APP", real, 1);
    setenv("RUSHES_NAME", NAME, 1);
    setenv("PYTHONDONTWRITEBYTECODE", "1", 1);
    setenv("PYTHONNOUSERSITE", "1", 1);
    unsetenv("PYTHONHOME");
    unsetenv("PYTHONPATH");

    // Opened from Finder (no arguments, or only old Finder noise): the window.
    // Started by launchd (--service): the work, and the icon.
    int ui = 1, service = 0;
    for (int i = 1; i < argc; i++) {
        if (strncmp(argv[i], "-psn", 4) != 0) ui = 0;
        if (strcmp(argv[i], "--service") == 0) service = 1;
    }
    char addr[96] = "";
    int port = ui || service ? free_port() : 0;
    if (port) {
        unsigned char b[16]; arc4random_buf(b, sizeof b);
        for (int i = 0; i < 16; i++) snprintf(key + 2 * i, 3, "%02x", b[i]);
        snprintf(port_s, sizeof port_s, "%d", port);
        snprintf(addr, sizeof addr, "http://127.0.0.1:%d/%s/", port, key);
    }

    char **args = calloc((size_t)argc + 6, sizeof *args);
    int k = 0;
    args[k++] = PY; args[k++] = "-u"; args[k++] = SCRIPT;
    if (port && ui) { args[k++] = "--window"; args[k++] = port_s; args[k++] = key; }
    for (int i = 1; i < argc; i++)
        if (strncmp(argv[i], "-psn", 4) != 0) args[k++] = argv[i];   // old Finder noise
    args[k] = NULL;

    signal(SIGTERM, pass_on); signal(SIGINT, pass_on); signal(SIGHUP, pass_on);
    int err = posix_spawn(&child, PY, NULL, NULL, args, environ);
    if (err) { fprintf(stderr, "%s: cannot start %s (%s)\n", NAME, PY, strerror(err)); return 1; }
    atexit(stop_child);

    if (port && service) {
        if (menubar()) return 0;
        // No icon, nothing running: the work is stopped, and why is said in its log.
        kill(child, SIGTERM);
        fprintf(stderr, "%s cannot show its icon in the menu bar on this Mac, so it does not run "
                        "(nothing runs out of sight). Trying again in 10 minutes.\n", NAME);
        sleep(600);
        return 1;
    }
    if (port) {
        for (int i = 0; i < 150 && !answers(port); i++) usleep(100000);   // the page, up to 15 s
        if (window(addr)) return 0;
        // No window possible here: the same page, in the browser.
        char *open_args[] = {"/usr/bin/open", addr, NULL}; pid_t o;
        posix_spawn(&o, "/usr/bin/open", NULL, NULL, open_args, environ);
    }

    int status = 0;
    while (waitpid(child, &status, 0) < 0) if (errno != EINTR) return 1;
    if (WIFEXITED(status)) return WEXITSTATUS(status);
    return 128 + WTERMSIG(status);
}
