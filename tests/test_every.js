// every() in head.php: a hidden tab asks nothing; slow or failed answers double
// the wait up to a minute; the first good one goes back to normal.
// Run: node tests/test_every.js
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../app/head.php', 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];

let hidden=false, now=0, timers=[], calls=0, fail=true, listeners=[];
global.window=global; global.document={get hidden(){return hidden}, addEventListener:(e,f)=>listeners.push(f), getElementById:()=>null, documentElement:{dataset:{}}};
global.localStorage={getItem:()=>null};
global.fetch=()=>fail?Promise.reject(new Error('x')):Promise.resolve({status:200});
global.setTimeout=(f,ms)=>timers.push([ms,f]);
eval(src);
const ok = (c, m) => { if (!c) { console.log('FAIL ' + m); process.exit(1); } console.log('PASS ' + m); };
const waits=[];
(async()=>{
  every(()=>{calls++; return fetch('/x');}, 5000);
  for (let i=0;i<6;i++){ await new Promise(r=>setImmediate(r)); const [ms,f]=timers.shift(); waits.push(ms); f(); }
  ok(JSON.stringify(waits)==='[10000,20000,40000,60000,60000,60000]', 'backoff '+waits);
  fail=false; await new Promise(r=>setImmediate(r)); ok(timers[0][0]===5000,'back to normal');
  hidden=true; const c=calls; timers.shift()[1](); listeners[0](); await new Promise(r=>setImmediate(r));
  ok(calls===c && timers.length===0, 'hidden asks nothing');
  hidden=false; listeners[0](); await new Promise(r=>setImmediate(r)); ok(calls===c+1,'shown again: asks at once');
  console.log('every(): all checks pass');
})();
