'use strict';
const districts=['Ranchi','Khunti','Ramgarh','Hazaribagh','Bokaro','Dhanbad','Giridih','Koderma','Chatra','Latehar','Lohardaga','Gumla','Simdega','Palamu','Garhwa','East Singhbhum','West Singhbhum','Seraikela Kharsawan','Deoghar','Dumka','Jamtara','Godda','Pakur','Sahibganj'];
const hindi=['रांची','खूंटी','रामगढ़','हजारीबाग','बोकारो','धनबाद','गिरिडीह','कोडरमा','चतरा','लातेहार','लोहरदगा','गुमला','सिमडेगा','पलामू','गढ़वा','पूर्वी सिंहभूम','पश्चिम सिंहभूम','सरायकेला खरसावां','देवघर','दुमका','जामताड़ा','गोड्डा','पाकुड़','साहिबगंज'];
const form=document.querySelector('#enquiry'),district=document.querySelector('#district'),bill=document.querySelector('#bill'),nameInput=document.querySelector('#customer-name'),mobile=document.querySelector('#mobile'),button=document.querySelector('#continue'),status=document.querySelector('#status'),consent=document.querySelector('#contact-consent');
districts.forEach((d,i)=>district.add(new Option(hindi[i]+' / '+d,d)));
['0-1000','1001-2500','2501-3500','3501-4500','4500+'].forEach((v,i)=>bill.options[i+1].value=v);
let busy=false,receipt=null,step=0;
const english=()=>window.solarLang==='en';
const say=(en,hi)=>english()?en:hi;
const validMobile=()=>/^(?:91)?[6-9]\d{9}$/.test(mobile.value.replace(/[\s()+-]/g,''));
function ready(){return districts.includes(district.value)&&bill.value&&nameInput.value.trim().length>=2&&validMobile()&&consent.checked;}
function update(){button.disabled=busy||!ready();if(!receipt)status.textContent=ready()?say('Ready. Save your enquiry to request a response.','जानकारी पूरी है। संपर्क के लिए enquiry सेव करें।'):say('Enter your name, valid mobile number and permission to respond.','नाम, सही मोबाइल नंबर और संपर्क की अनुमति दें।');}
form.addEventListener('input',update);form.addEventListener('change',update);
// Keep only a random retry key in session storage; never persist customer fields in browser storage.
let requestId;try{requestId=sessionStorage.getItem('residential-request');}catch{}
if(!/^[a-f0-9-]{32,40}$/.test(requestId||'')){requestId=crypto.randomUUID();try{sessionStorage.setItem('residential-request',requestId);}catch{}}
async function csrf(){const r=await fetch('intake.php',{credentials:'same-origin',cache:'no-store'});if(!r.ok)throw new Error('Form service unavailable');return (await r.json()).csrf;}
form.addEventListener('submit',async e=>{
 e.preventDefault();if(busy||receipt||!ready())return;busy=true;update();status.textContent=say('Saving your enquiry…','आपकी enquiry सेव हो रही है…');
 try{
  const token=await csrf();const query=new URLSearchParams(location.search);const source={};
  // Source codes are allowlisted on the server too. No URL or personal field reaches analytics.
  for(const [key,param] of Object.entries({source:'utm_source',medium:'utm_medium',campaign:'utm_campaign',creative:'utm_content'})){const v=query.get(param)||'';source[key]=/^[A-Za-z0-9_-]{1,80}$/.test(v)?v:'';}
  const payload={...source,csrf:token,request_id:requestId,name:nameInput.value.trim(),phone:mobile.value,district:district.value,bill:bill.value,language:english()?'en':'hi',consent:'1',measurement_consent:document.querySelector('#measurement-consent').checked?'1':'0',website:document.querySelector('#website-trap').value};
  const response=await fetch('intake.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});const data=await response.json();if(!response.ok||!data.reference)throw new Error(data.error||'Could not confirm saving.');
  receipt=data.reference;form.append(status);form.querySelectorAll('.form-step,.form-progress,#form-next,#form-back,#continue').forEach(el=>el.hidden=true);
  status.textContent=say('Enquiry saved. Reference: ','Enquiry सेव हो गई। संदर्भ: ')+receipt+say('. Our team will review it and contact you during calling hours. No survey is booked.','. टीम देखकर कॉल के समय संपर्क करेगी। Survey अभी बुक नहीं हुआ है।')+(data.mode!=='live'?say(' PREVIEW TEST ONLY — no owner alert sent.',' केवल PREVIEW TEST — owner alert नहीं भेजा गया।'):'');
  const link=document.createElement('a');link.className='button';link.textContent=say('Open WhatsApp (optional)','WhatsApp खोलें (वैकल्पिक)');link.href='https://wa.me/917070278178?text='+encodeURIComponent('Residential solar enquiry reference: '+receipt+'. Please help with my home solar plan.');link.target='_blank';link.rel='noopener noreferrer';
  link.addEventListener('click',()=>{fetch('intake.php',{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:token,action:'handoff',reference:receipt})}).catch(()=>{});});
  const hint=document.createElement('p');hint.textContent=say('WhatsApp opens a prepared message. Press Send there to deliver it. Your enquiry is already saved even if you do not use WhatsApp.','WhatsApp में तैयार संदेश खुलेगा। पहुंचाने के लिए वहाँ Send दबाएं। WhatsApp न भेजने पर भी आपकी enquiry सेव है।');status.after(link,hint);
  const again=document.createElement('button');again.type='button';again.textContent=say('Start a separate new enquiry','अलग नई enquiry शुरू करें');again.addEventListener('click',()=>{try{sessionStorage.removeItem('residential-request');}catch{}location.reload();});hint.after(again);
 }catch(error){status.textContent=say('Saving was not confirmed. Retry with the same details; this will not create a second record. Or call 7070278178. ','सेव होने की पुष्टि नहीं हुई। वही जानकारी रखकर दोबारा कोशिश करें; दूसरी record नहीं बनेगी। या 7070278178 पर कॉल करें। ')+error.message;}
 finally{busy=false;button.disabled=!!receipt||!ready();}
});
const steps=[...document.querySelectorAll('.form-step')],next=document.querySelector('#form-next'),back=document.querySelector('#form-back');
function stepReady(){return step===0?districts.includes(district.value):step===1?!!bill.value:ready();}
function draw(){steps.forEach((s,i)=>s.hidden=i!==step);back.hidden=step===0;next.hidden=step===2;next.disabled=!stepReady();document.querySelector('#step-label').textContent=say('Step ','चरण ')+(step+1)+'/3';document.querySelector('#step-progress').style.width=((step+1)/3*100)+'%';}
next.addEventListener('click',()=>{if(!stepReady())return;step=Math.min(2,step+1);draw();(step===1?bill:nameInput).focus({preventScroll:true});});back.addEventListener('click',()=>{step=Math.max(0,step-1);draw();});form.addEventListener('change',()=>next.disabled=!stepReady());form.addEventListener('input',()=>next.disabled=!stepReady());draw();update();
