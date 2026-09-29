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
// Opened from Finder: runs the setup. Started by launchd with arguments:
// runs the helper. Both are Contents/Resources/rushes_helper.py.
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

#if defined(__aarch64__)
#define ARCH "arm64"
#else
#define ARCH "x86_64"
#endif

extern char **environ;
static pid_t child = 0;
static void pass_on(int sig) { if (child > 0) kill(child, sig); }

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

    char **args = calloc((size_t)argc + 4, sizeof *args);
    int k = 0;
    args[k++] = py; args[k++] = "-u"; args[k++] = script;
    for (int i = 1; i < argc; i++)
        if (strncmp(argv[i], "-psn", 4) != 0) args[k++] = argv[i];   // old Finder noise
    args[k] = NULL;

    signal(SIGTERM, pass_on); signal(SIGINT, pass_on); signal(SIGHUP, pass_on);
    int err = posix_spawn(&child, py, NULL, NULL, args, environ);
    if (err) { fprintf(stderr, "Rushes Helper: cannot start %s (%s)\n", py, strerror(err)); return 1; }

    int status = 0;
    while (waitpid(child, &status, 0) < 0) if (errno != EINTR) return 1;
    if (WIFEXITED(status)) return WEXITSTATUS(status);
    return 128 + WTERMSIG(status);
}
