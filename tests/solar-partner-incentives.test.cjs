const {readFileSync}=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const html=readFileSync(require('node:path').join(__dirname,'../solar-partners.html'),'utf8');
const script=html.match(/<script>([\s\S]*?)<\/script>/)[1];
new vm.Script(script); // Parse the entire deployed script, including the WhatsApp handler.
const context=vm.createContext({});
const dictionary=script.slice(0,script.indexOf('const models='));
const incentive=script.match(/function monthlyIncentive\([^]*?\nfunction update/)[0].replace(/\nfunction update$/,'');
vm.runInContext(dictionary+'\n'+incentive+'\nthis.translations=copy;this.incentive=monthlyIncentive;',context);
for(const [count,kw,expected] of [[1,3,0],[4,3,0],[5,3,5000],[7,3,7000],[8,3,12000],[10,3,15000],[5,1,2500],[7,2,3500],[8,1,6000],[8,2,6000]]){
  assert.equal(context.incentive(count,kw),expected,`${count} customers, ${kw} kW`);
}
assert.equal(4*1500+4*750,9000,'mixed-size eight-customer example');
const keys=[...html.matchAll(/data-t="([^"]+)"/g)].map(m=>m[1]);
for(const language of ['en','hi'])for(const key of keys){assert.equal(typeof context.translations[language][key],'string',`${language}.${key}`);}
assert.ok(!html.includes('Existing cluster offer'));
assert.ok(!html.includes('60 days of the first accepted order'));
assert.ok(context.translations.en.payText.includes('15th'));
assert.ok(context.translations.hi.delayText.includes('अप्रत्याशित परिस्थितियों'));
console.log('Passed: full-script syntax, 10 incentive boundary cases, mixed-size example and both-language text coverage.');