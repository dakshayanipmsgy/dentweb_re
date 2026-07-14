(function(){
  'use strict';
  const DESKTOP_LIMIT=10, MOBILE_LIMIT=3, READY_TIMEOUT=30000, SCALE_KEY='quotationBrowserExportScalePercent';
  const $=(s,r=document)=>r.querySelector(s);
  const $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const mobile=()=>matchMedia('(pointer:coarse)').matches||Math.min(screen.width,innerWidth)<900;
  const limit=()=>mobile()?MOBILE_LIMIT:DESKTOP_LIMIT;
  let activeUrl=null, finalBlob=null, finalName='';
  function setStatus(msg){ const el=$('#quotationBrowserExportStatus'); if(el) el.textContent=msg; }
  function code(c,m,diagnostics){ const e=new Error(m); e.code=c; if(diagnostics) e.diagnostics=diagnostics; return e; }
  function scaleSelect(){return $('#quotationBrowserExportScale');}
  function validScale(v){ const n=parseInt(v,10); return [50,60,70,80,90,100].includes(n)?n:100; }
  function selectedScale(){ return validScale(scaleSelect()?.value||localStorage.getItem(SCALE_KEY)||100); }
  function initScale(){ const el=scaleSelect(); if(!el) return; el.value=String(validScale(localStorage.getItem(SCALE_KEY)||el.value||100)); el.addEventListener('change',()=>localStorage.setItem(SCALE_KEY,String(validScale(el.value)))); }
  function selectedIds(form){ const seen=new Set(), out=[]; $$('.bulk-row-check',form).forEach(c=>{ if(c.checked&&!seen.has(c.value)){seen.add(c.value); out.push(c.value);} }); return out; }
  function canvasOk(){ const c=document.createElement('canvas'); return !!(c.getContext&&c.getContext('2d')&&(c.toBlob||c.toDataURL)); }
  function requireSupport(){
    const missing=[];
    if(!window.Promise||!window.fetch||!window.Blob||!window.File||!window.FormData||!window.URL?.createObjectURL||!window.Uint8Array||!canvasOk()) missing.push('browser_feature_unsupported');
    if(!window.html2canvas||!window.jspdf?.jsPDF||!window.fflate?.zipSync) missing.push('asset_missing');
    if(missing.includes('asset_missing')) throw code('asset_missing','Browser export assets are missing from this deployment.');
    if(missing.length) throw code('browser_feature_unsupported','This browser lacks a required file, canvas, Blob, or download API.');
  }
  async function postSession(form, ids){
    const fd=new FormData(); fd.set('action','quotation_browser_export_session'); fd.set('csrf_token',form.querySelector('[name=csrf_token]')?.value||''); ids.forEach(id=>fd.append('selected_ids[]',id)); fd.set('export_scale_percent', String(selectedScale()));
    const res=await fetch('admin-quotations.php',{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},body:fd});
    const data=await res.json().catch(()=>null); if(!res.ok||!data?.ok) throw code('asset_load_failed',data?.message||'Unable to create browser export session.'); return data;
  }
  function wait(ms){return new Promise(r=>setTimeout(r,ms));}
  async function waitReady(frame){
    const start=Date.now();
    while(Date.now()-start<READY_TIMEOUT){
      const w=frame.contentWindow, d=frame.contentDocument;
      if(w?.__quotationPdfError){ const er=w.__quotationPdfError; if(typeof er==='object') throw code(er.code||'pagination_failed',er.message||'Quotation pagination failed.',er.diagnostics||{}); throw code('pagination_failed',String(er).replace(/^pagination_failed:\s*/i,'')); }
      if(w&&d&&w.__quotationPdfReady===true){
        if(d.fonts?.ready) await d.fonts.ready;
        await Promise.all($$('img',d).map(img=>img.complete?Promise.resolve():new Promise(r=>{img.addEventListener('load',r,{once:true});img.addEventListener('error',r,{once:true});})));
        return;
      }
      await wait(150);
    }
    throw code('paginator_load_timeout','Readiness timeout while waiting for quotation pagination, fonts, charts, and images.');
  }
  function pageElements(doc){ const pages=$$('.pagedjs_page',doc).filter(e=>e.offsetWidth&&e.offsetHeight); if(!pages.length) throw code('pagination_failed','Pagination did not produce page elements.'); pages.forEach((page,i)=>{ const r=page.getBoundingClientRect(); if(r.width<760||r.width>830||r.height<1080||r.height>1165) throw code('pagination_failed',`Page ${i+1} is not a usable A4 page (${Math.round(r.width)}x${Math.round(r.height)}px).`); }); const h=Math.max(doc.documentElement.scrollHeight,doc.body.scrollHeight); if(h>1300&&pages.length<2) throw code('pagination_failed','Long quotation produced only one page after pagination.'); return pages; }
  async function blobBytes(blob){ return new Uint8Array(await blob.arrayBuffer()); }
  async function validatePdfBlob(blob, expectedPages){ const bytes=await blobBytes(blob); const header=new TextDecoder('ascii').decode(bytes.slice(0,5)); if(header!=='%PDF-') throw code('pdf_validation_failed','Generated file is not a PDF.'); const text=new TextDecoder('latin1').decode(bytes.slice(0, Math.min(bytes.length, 2000000))); const pageMatches=text.match(/\/Type\s*\/Page(?!s)/g)||[]; if(pageMatches.length!==expectedPages) throw code('pdf_validation_failed',`PDF page count ${pageMatches.length} did not match paginator page count ${expectedPages}.`); if(/Quotation PDF page/.test(text)) throw code('pdf_validation_failed','Placeholder PDF output was detected.'); if(!/\/Image\b/.test(text)) throw code('pdf_validation_failed','Rendered page images were not embedded in the PDF.'); return bytes; }
  function offscreenFrame(){ const frame=document.createElement('iframe'); frame.setAttribute('aria-hidden','true'); frame.style.cssText='position:fixed;left:-12000px;top:0;width:794px;height:1123px;border:0;opacity:0.01;pointer-events:none;background:#fff'; return frame; }
  async function renderPdf(item, token, index, total, cancelled){
    if(cancelled()) throw code('download_failed','Export cancelled.');
    const pct=selectedScale(); setStatus(`Preparing quotation ${index+1} of ${total} at ${pct}%`);
    const frame=offscreenFrame(); document.body.appendChild(frame);
    try{
      await new Promise((resolve,reject)=>{ frame.onload=resolve; frame.onerror=()=>reject(code('asset_load_failed','Unable to load quotation iframe.')); frame.src=`admin-quotations.php?action=quotation_browser_export_render&token=${encodeURIComponent(token)}&id=${encodeURIComponent(item.id)}`; });
      if(!frame.contentDocument) throw code('browser_feature_unsupported','Same-origin iframe access is unavailable.');
      await waitReady(frame);
      const doc=frame.contentDocument, pdf=new window.jspdf.jsPDF({orientation:'portrait',unit:'mm',format:'a4',compress:true}), pages=pageElements(doc), scale=mobile()?1.05:1.35, quality=mobile()?0.78:0.86;
      for(let p=0;p<pages.length;p++){
        if(cancelled()) throw code('download_failed','Export cancelled.');
        setStatus(`Rendering page ${p+1} of ${pages.length}`);
        const canvas=await window.html2canvas(pages[p],{scale,useCORS:true,backgroundColor:'#ffffff',logging:false,windowWidth:794,windowHeight:1123}).catch(e=>{throw code('render_failed',e?.message||'Page rendering failed.');});
        const img=canvas.toDataURL('image/jpeg',quality); if(p>0) pdf.addPage('a4','portrait'); pdf.addImage(img,'JPEG',0,0,210,297,undefined,'FAST'); canvas.width=canvas.height=0;
      }
      const blob=pdf.output('blob'); await validatePdfBlob(blob,pages.length); return blob;
    } finally { frame.src='about:blank'; frame.remove(); }
  }
  function cleanupDownload(){ if(activeUrl){ URL.revokeObjectURL(activeUrl); activeUrl=null; } finalBlob=null; finalName=''; const box=$('#quotationBrowserExportDownload'); if(box) box.remove(); }
  function showDownload(blob,name){
    cleanupDownload(); finalBlob=blob; finalName=name; activeUrl=URL.createObjectURL(blob);
    const box=document.createElement('div'); box.id='quotationBrowserExportDownload'; box.className='quote-meta'; box.style.marginBottom='12px';
    const primary=document.createElement('button'); primary.type='button'; primary.className='btn'; primary.textContent=name.endsWith('.zip')?'Download ZIP':'Download PDF';
    primary.onclick=()=>{ const a=document.createElement('a'); a.href=activeUrl; a.download=finalName; document.body.appendChild(a); a.click(); a.remove(); setStatus(/iPad|Macintosh/.test(navigator.userAgent)&&navigator.maxTouchPoints>1?'Ready to download. Use Share, then Save to Files if Safari opens the file.':'Ready to download'); };
    box.appendChild(primary);
    try{ const f=new File([blob],name,{type:blob.type}); if(navigator.canShare?.({files:[f]})){ const share=document.createElement('button'); share.type='button'; share.className='btn secondary'; share.textContent='Save / Share file'; share.onclick=()=>navigator.share({files:[f],title:name}).catch(()=>{}); box.appendChild(document.createTextNode(' ')); box.appendChild(share); } }catch(e){}
    $('#quotationBrowserExportCancel')?.insertAdjacentElement('afterend',box); setStatus('Ready to download');
  }
  async function run(form){
    let cancelled=false; cleanupDownload(); const cancel=$('#quotationBrowserExportCancel'); if(cancel) cancel.hidden=false; const onCancel=()=>{cancelled=true;}; cancel?.addEventListener('click',onCancel,{once:true});
    try{
      requireSupport(); const ids=selectedIds(form), max=limit(); if(!ids.length) throw code('download_failed','Select at least one quotation.'); if(ids.length>max) throw code('download_failed',`Browser export limit on this device is ${max} quotations. Select fewer quotations before export.`);
      setStatus(`Browser PDF/ZIP exporter ready at ${selectedScale()}%. Limit on this device: ${max} quotation(s).`);
      const session=await postSession(form,ids), rendered=[], files={}; if(session.scale_percent) localStorage.setItem(SCALE_KEY,String(validScale(session.scale_percent)));
      for(let i=0;i<session.items.length;i++){ const item=session.items[i]; const blob=await renderPdf(item,session.token,i,session.items.length,()=>cancelled); rendered.push({name:item.filename, bytes:await blobBytes(blob)}); }
      if(cancelled) throw code('download_failed','Export cancelled.');
      if(session.items.length===1){ const single=rendered[0]; showDownload(new Blob([files[single.name]=single.bytes],{type:'application/pdf'}),single.name); return; }
      setStatus('Creating ZIP'); rendered.forEach(file=>{ files[file.name]=file.bytes; }); const zipped=window.fflate.zipSync(files,{level:6}); showDownload(new Blob([zipped],{type:'application/zip'}),`quotations-browser-${new Date().toISOString().replace(/[:.]/g,'-')}.zip`);
    } catch(e){ setStatus(`${e?.code||'download_failed'}: ${e?.message||e}. Selection preserved for retry.`); }
    finally { if(cancel) cancel.hidden=true; cancel?.removeEventListener('click',onCancel); }
  }
  document.addEventListener('error',e=>{ if(e.target?.tagName==='SCRIPT'&&/browser-export/.test(e.target.src||'')) setStatus('asset_load_failed: Browser export asset failed to load.'); }, true);
  initScale();
  document.addEventListener('click',e=>{ const btn=e.target.closest('[data-browser-quotation-export]'); if(!btn) return; e.preventDefault(); const form=$('#quoteBulkForm'); if(form) run(form); });
  document.addEventListener('submit',e=>{ const submitter=e.submitter; if(submitter?.name==='action'&&submitter.value==='bulk_download_quotation_pdfs'&&submitter.dataset.serverPdfAvailable==='0'){ e.preventDefault(); const form=$('#quoteBulkForm'); if(form) run(form); } });
  window.addEventListener('pagehide',cleanupDownload);
})();
