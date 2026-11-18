"""Hold the watchdog's real sleep at its kernel exit (PTRACE_EVENT_EXIT) while build.sh's own watchdog lines run.

Run from the repository root: python3 -I .evidence/2466/hold-exit.py "$TMPDIR"
The session, the watchdog, its group kill and the wait are build.sh's lines, cut from the file at run time.
The checks are the test's own isRunning (the old immediate filter) and stillRunningAfter (the bounded wait).
"""
import ctypes, os, subprocess, sys, tempfile, time

PTRACE_CONT, PTRACE_SEIZE, PTRACE_O_TRACEEXIT, PTRACE_EVENT_EXIT, WALL = 7, 0x4206, 0x40, 6, 0x40000000
libc = ctypes.CDLL(None, use_errno=True)
libc.ptrace.argtypes = [ctypes.c_long, ctypes.c_long, ctypes.c_void_p, ctypes.c_void_p]

root = os.getcwd()
record = tempfile.mkdtemp(dir=sys.argv[1])
bin_dir = os.path.join(record, 'bin')
os.mkdir(bin_dir)
real_sleep = subprocess.check_output(['sh', '-c', 'command -v sleep'], text=True).strip()
for name, body in {
    'claude': 'until [ -e "$RECORD/seized" ]; do %s 0.01; done\nexit 0\n' % real_sleep,
    'sleep': 'echo $$ >> "$RECORD/sleeps"\nexec %s "$@"\n' % real_sleep,
}.items():
    with open(os.path.join(bin_dir, name), 'w') as script:
        script.write('#!/bin/sh\n' + body)
    os.chmod(os.path.join(bin_dir, name), 0o755)

build = open(os.path.join(root, 'docker/job/build.sh')).read()
start = build.index('setsid claude -p')
end = build.index('cut=$?') + len('cut=$?')
fragment = 'model=m turns=1 prompt=/dev/null log="$RECORD/log" allowance=31\n' + build[start:end] + '\necho "build.sh fragment returned: status=$status cut=$cut"\n'
env = dict(os.environ, RECORD=record, PATH=bin_dir + ':' + os.environ['PATH'])
shell = subprocess.Popen(['/bin/sh', '-c', fragment], env=env, stdout=subprocess.PIPE, text=True)

sleeps = os.path.join(record, 'sleeps')
while not (os.path.exists(sleeps) and open(sleeps).read().strip()):
    time.sleep(0.01)
pid = int(open(sleeps).read().split()[0])
while open('/proc/%d/comm' % pid).read().strip() != 'sleep':
    time.sleep(0.01)
if libc.ptrace(PTRACE_SEIZE, pid, None, PTRACE_O_TRACEEXIT) != 0:
    sys.exit('seize failed: errno %d' % ctypes.get_errno())
open(os.path.join(record, 'seized'), 'w').close()

_, status = os.waitpid(pid, WALL)
print('sleep %d stopped at PTRACE_EVENT_EXIT: %s' % (pid, os.WIFSTOPPED(status) and status >> 16 == PTRACE_EVENT_EXIT))
print(shell.communicate()[0].strip())

check = '''require "vendor/autoload.php";
$test = new Intakt\\Tests\\Core\\Build\\BuildScriptTest("t");
$call = fn (string $method, mixed ...$arguments) => new ReflectionMethod($test, $method)->invoke($test, ...$arguments);
$pid = (int) $argv[2];
echo $argv[1] === "immediate"
    ? "immediate check (old test): ".json_encode(array_values(array_filter([$pid], fn (int $p) => $call("isRunning", $p))))
    : "bounded check (5 s): ".json_encode($call("stillRunningAfter", [$pid], 5)), "\\n";'''
state = open('/proc/%d/stat' % pid).read().rsplit(')', 1)[1].split()[0]
print('state in /proc right after the fragment returned: %s' % state)
print(subprocess.check_output(['php', '-r', check, 'immediate', str(pid)], text=True).strip())
began = time.monotonic()
bounded = subprocess.Popen(['php', '-r', check, 'bounded', str(pid)], stdout=subprocess.PIPE, text=True)
time.sleep(1)
libc.ptrace(PTRACE_CONT, pid, None, None)
print(bounded.communicate()[0].strip() + ' after %.2f s (sleep released from its exit at 1 s)' % (time.monotonic() - began))
