// Relinking FCPXML and XML from Final Cut and Resolve: a file address to a path and back.
// Run: node tests/test_relink.js   (reads the functions from admin.php itself, Editors' projects)
const fs = require('fs'), path = require('path');
const s = fs.readFileSync(path.join(__dirname, '..', 'app', 'db', 'admin.php'), 'utf8');
const code = s.slice(s.indexOf('      var toPath'), s.indexOf("      $('rlGo').onclick"));
const { toPath, toUrl } = new Function(code + '; return { toPath, toUrl };')();
let bad = 0;
const eq = (a, b, what) => { if (a !== b) { bad++; console.log('FAIL', what, JSON.stringify(a), '!=', JSON.stringify(b)); } else console.log('PASS', what); };
eq(toPath('file:///Volumes/VIDEO/ARCHIVE/a%20b/K.MXF'), '/Volumes/VIDEO/ARCHIVE/a b/K.MXF', 'a Mac address, with a space');
eq(toPath('file://localhost/Volumes/VIDEO/x.mov'), '/Volumes/VIDEO/x.mov', 'an address with localhost');
eq(toPath('file:///Z:/ARCHIVE/x.mov'), 'Z:\\ARCHIVE\\x.mov', 'a Windows drive');
eq(toPath('file://nas/VIDEO/x.mov'), '\\\\nas\\VIDEO\\x.mov', 'a network share');
eq(toPath('file:///bad%E0%A4%A'), '', 'a broken address is left alone');
eq(toUrl('/Volumes/VIDEO/Library/P K/x.mov', 'file:///Volumes/old'), 'file:///Volumes/VIDEO/Library/P%20K/x.mov', 'back to a Mac address');
eq(toUrl('/Volumes/VIDEO/Library/x.mov', 'file://localhost/Volumes/old'), 'file://localhost/Volumes/VIDEO/Library/x.mov', 'written the way it was');
eq(toUrl('Z:\\Library\\x.mov', 'file:///Z:/old'), 'file:///Z:/Library/x.mov', 'back to a Windows drive');
eq(toUrl('\\\\nas\\VIDEO\\Library\\x.mov', 'file://nas/x'), 'file://nas/VIDEO/Library/x.mov', 'back to a network share');
if (bad) process.exit(1);
console.log('relink: all checks pass');
