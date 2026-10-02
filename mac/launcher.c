// Rushes — Media Management Software, by Alejandro Renteria.
// Source available: https://github.com/x0on/rushes — whoever finds this file on a computer can see what it is and who made it.
// Rushes Helper — the app's own front door.
//
// macOS decides who may open network drives and other disks by asking which
// APP is responsible for a process. A Python script has no app, so it shows
// up as "python3" — and in System Settings someone has to find and allow a
// program they have never heard of. This tiny program is that app: it starts
// the Python that ships inside Rushes Helper.app as its own child, so what
// macOS sees, lists and asks about is "Rushes Helper", with its icon.
//
// It stays running for as long as the child does (never exec: the child
// must keep this app as the responsible one), passes on stop signals, and
// exits with the child's status so launchd can restart it.
//
// Started by launchd with arguments: runs the helper, no window.
// Opened from Finder: shows the app's one window — setup the first time, then
// what the helper is doing and its switches. The window is this app's own
// (its name in the menu bar, its icon in the Dock) and shows a page the
// Python child serves on this computer only (127.0.0.1, with a random key), so
// one program does the work and the page, and the window stays open start to end.
#include <limits.h>
#include <mach-o/dyld.h>
#include <signal.h>
#include <spawn.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
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
static pid_t child = 0;
static void pass_on(int sig) { if (child > 0) kill(child, sig); }
static void stop_child(void) { if (child > 0) kill(child, SIGTERM); }

// ── the Objective-C runtime, looked up at run time (no SDK needed to build) ──
typedef void *id; typedef void *SEL; typedef void *Class;
typedef struct { double x, y, w, h; } Rect;
static id (*getcls)(const char *); static SEL (*sel)(const char *); static void *msg;
static Class (*newcls)(Class, const char *, size_t); static void (*regcls)(Class);
static signed char (*addm)(Class, SEL, void *, const char *);
static id C(const char *n) { return getcls(n); }
static id m0(id o, const char *s) { return ((id (*)(id, SEL))msg)(o, sel(s)); }
static id m1(id o, const char *s, id a) { return ((id (*)(id, SEL, id))msg)(o, sel(s), a); }
static id str(const char *s) { return ((id (*)(id, SEL, const char *))msg)(C("NSString"), sel("stringWithUTF8String:"), s); }
static id item(const char *title, const char *action, const char *key) {
    return ((id (*)(id, SEL, id, SEL, id))msg)(m0(C("NSMenuItem"), "alloc"),
        sel("initWithTitle:action:keyEquivalent:"), str(title), action ? sel(action) : NULL, str(key));
}
static signed char yes(id self, SEL cmd, id a) { (void)self; (void)cmd; (void)a; return 1; }

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
    void *objc = dlopen("/usr/lib/libobjc.A.dylib", RTLD_NOW);
    if (!objc || !dlopen("/System/Library/Frameworks/AppKit.framework/AppKit", RTLD_NOW)
              || !dlopen("/System/Library/Frameworks/WebKit.framework/WebKit", RTLD_NOW)) return 0;
    getcls = dlsym(objc, "objc_getClass"); sel = dlsym(objc, "sel_registerName"); msg = dlsym(objc, "objc_msgSend");
    newcls = dlsym(objc, "objc_allocateClassPair"); regcls = dlsym(objc, "objc_registerClassPair");
    addm = dlsym(objc, "class_addMethod");
    if (!getcls || !sel || !msg || !newcls || !regcls || !addm || !C("WKWebView")) return 0;

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
    m1(menu, "addItem:", item("Quit Rushes Helper", "terminate:", "q"));
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
    ((void (*)(id, SEL, signed char))msg)(win, sel("setReleasedWhenClosed:"), 0);
    m1(win, "setTitle:", str("Rushes Helper"));
    id web = ((id (*)(id, SEL, Rect, id))msg)(m0(C("WKWebView"), "alloc"), sel("initWithFrame:configuration:"), r,
        m0(m0(C("WKWebViewConfiguration"), "alloc"), "init"));
    m1(win, "setContentView:", web);
    m1(web, "loadRequest:", m1(C("NSURLRequest"), "requestWithURL:", m1(C("NSURL"), "URLWithString:", str(addr))));
    m0(win, "center");
    m1(win, "makeKeyAndOrderFront:", NULL);
    ((void (*)(id, SEL, signed char))msg)(app, sel("activateIgnoringOtherApps:"), 1);

    pthread_t t; pthread_create(&t, NULL, watch, NULL);
    m0(app, "run");                    // until the window closes or the page says Done
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
        fprintf(stderr, "Rushes Helper: cannot tell where it is installed\n");
        return 1;
    }
    // real = <app>/Contents/MacOS/Rushes Helper  ->  cut back to <app>
    for (int i = 0; i < 3; i++) { char *s = strrchr(real, '/'); if (!s) return 1; *s = 0; }

    char py[PATH_MAX], script[PATH_MAX];
    snprintf(py, sizeof py, "%s/Contents/Resources/python-%s/bin/python3", real, ARCH);
    snprintf(script, sizeof script, "%s/Contents/Resources/rushes_helper.py", real);

    // The app stays exactly as it was signed: nothing is written inside it.
    setenv("RUSHES_APP", real, 1);
    setenv("PYTHONDONTWRITEBYTECODE", "1", 1);
    setenv("PYTHONNOUSERSITE", "1", 1);
    unsetenv("PYTHONHOME");
    unsetenv("PYTHONPATH");

    // Opened from Finder (no arguments, or only old Finder noise): the window.
    int ui = 1;
    for (int i = 1; i < argc; i++) if (strncmp(argv[i], "-psn", 4) != 0) ui = 0;
    char port_s[16] = "", key[33] = "", addr[96] = "";
    int port = ui ? free_port() : 0;
    if (port) {
        unsigned char b[16]; arc4random_buf(b, sizeof b);
        for (int i = 0; i < 16; i++) snprintf(key + 2 * i, 3, "%02x", b[i]);
        snprintf(port_s, sizeof port_s, "%d", port);
        snprintf(addr, sizeof addr, "http://127.0.0.1:%d/%s/", port, key);
    }

    char **args = calloc((size_t)argc + 6, sizeof *args);
    int k = 0;
    args[k++] = py; args[k++] = "-u"; args[k++] = script;
    if (port) { args[k++] = "--window"; args[k++] = port_s; args[k++] = key; }
    for (int i = 1; i < argc; i++)
        if (strncmp(argv[i], "-psn", 4) != 0) args[k++] = argv[i];   // old Finder noise
    args[k] = NULL;

    signal(SIGTERM, pass_on); signal(SIGINT, pass_on); signal(SIGHUP, pass_on);
    int err = posix_spawn(&child, py, NULL, NULL, args, environ);
    if (err) { fprintf(stderr, "Rushes Helper: cannot start %s (%s)\n", py, strerror(err)); return 1; }

    if (port) {
        atexit(stop_child);
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
