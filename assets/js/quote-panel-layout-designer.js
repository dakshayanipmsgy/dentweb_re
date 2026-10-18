(function(){
  const defaults={grid:{columns:36,rows:24,cell_unit:'grid',editor_cell_px:18,major_line_every:5,customer_grid_visible:false},objects:[]};
  const limits={min:8,max:100,minCell:14,maxCell:28};
  const panelProfiles={portrait:{w:2,h:4,aspect:0.52},landscape:{w:4,h:2,aspect:1.92}};
  const startupError='Layout designer could not start. Refresh the page or contact support.';

  // Layout values contain JSON-compatible data only. Native structuredClone is
  // preferred, but older Safari versions do not provide it and an individual
  // value can still cause it to throw.
  function cloneLayout(value){
    if(typeof window.structuredClone==='function'){
      try{return window.structuredClone(value);}catch(e){/* Fall through. */}
    }
    return JSON.parse(JSON.stringify(value));
  }

  function showStartupError(root,error){
    root.dataset.layoutReady='0';
    root.dataset.layoutError='1';
    root.classList.add('has-layout-error');
    const status=root.querySelector('[data-layout-status]');
    if(status){status.textContent=startupError;status.classList.add('error');}
    if(window.console&&typeof window.console.error==='function') window.console.error('[Panel Layout Designer] Initialization failed',error);
  }

  function initialize(root){
    // Mark before doing any work so a second script execution cannot attach a
    // second set of handlers, even if the first initialization fails.
    if(root.dataset.layoutInitialized==='1')return;
    root.dataset.layoutInitialized='1';
    root.dataset.layoutReady='0';
    try{init(root);root.dataset.layoutReady='1';delete root.dataset.layoutError;}
    catch(error){showStartupError(root,error);}
  }

  function bootstrap(){
    document.querySelectorAll('[data-panel-layout-designer]').forEach(initialize);
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',bootstrap,{once:true});
  else bootstrap();

  function init(root){
    const form=root.closest('form'); const input=(form&&form.querySelector('#panelOrientationJson'))||document.getElementById('panelOrientationJson'); const touchedInput=(form&&form.querySelector('#panelOrientationLayoutTouched'))||document.getElementById('panelOrientationLayoutTouched'); const grid=root.querySelector('[data-layout-grid]'); const status=root.querySelector('[data-layout-status]'); const textInput=root.querySelector('[data-layout-text]');
    const colInput=root.querySelector('[data-layout-columns]'); const rowInput=root.querySelector('[data-layout-rows]'); const zoomLabel=root.querySelector('[data-layout-zoom-label]'); const scroller=root.querySelector('[data-layout-scroll]');
    if(!input||!touchedInput||!grid||!status)throw new Error('Required designer element is missing');
    let layout=safeParse(root.dataset.initialLayout||input.value)||cloneLayout(defaults); layout.grid=Object.assign(cloneLayout(defaults.grid),layout.grid||{}); layout.objects=Array.isArray(layout.objects)?layout.objects:[]; layout.objects.forEach(normalizePanel); let selected=null, drag=null;
    function clamp(n,min,max,fb){n=parseInt(n,10);return Number.isFinite(n)?Math.max(min,Math.min(max,n)):fb} function cols(){return clamp(layout.grid.columns,limits.min,limits.max,defaults.grid.columns)} function rows(){return clamp(layout.grid.rows,limits.min,limits.max,defaults.grid.rows)} function cellPx(){return clamp(layout.grid.editor_cell_px,limits.minCell,limits.maxCell,defaults.grid.editor_cell_px)}
    function syncGrid(){layout.grid.columns=cols();layout.grid.rows=rows();layout.grid.cell_unit='grid';layout.grid.editor_cell_px=cellPx();layout.grid.major_line_every=5;layout.grid.customer_grid_visible=!!layout.grid.customer_grid_visible;if(colInput)colInput.value=layout.grid.columns;if(rowInput)rowInput.value=layout.grid.rows;if(zoomLabel)zoomLabel.textContent=Math.round(layout.grid.editor_cell_px/defaults.grid.editor_cell_px*100)+'%';}
    function cell(){return {w:cellPx(),h:cellPx()};}
    function box(o){return {x:+o.x||0,y:+o.y||0,w:+o.w||1,h:+o.h||1};}
    function panelProfile(orientation){return panelProfiles[orientation==='landscape'?'landscape':'portrait'];}
    function normalizePanel(o){if(!o||o.type!=='panel')return; const profile=panelProfile(o.orientation||(+o.w>+o.h?'landscape':'portrait')); o.orientation=profile===panelProfiles.landscape?'landscape':'portrait'; o.w=profile.w; o.h=profile.h; o.visual_aspect_ratio=profile.aspect;}
    function overlaps(a,b){return a.x<b.x+b.w&&a.x+a.w>b.x&&a.y<b.y+b.h&&a.y+a.h>b.y;}
    function blockers(o){return ['panel','text','obstruction'].indexOf(o.type)!==-1}
    function invalidReason(o){const b=box(o); if(b.x<0||b.y<0||b.x+b.w>cols()||b.y+b.h>rows()) return `${labelFor(o)} is outside the grid.`; if(blockers(o)){const hit=layout.objects.find(other=>other!==o&&blockers(other)&&overlaps(b,box(other))); if(hit) return `${labelFor(o)} overlaps ${labelFor(hit)}.`;} return '';}
    function validateAll(){for(const o of layout.objects){const msg=invalidReason(o); if(msg)return msg;} return '';}
    function valid(o){return invalidReason(o)==='';}
    function labelFor(o){return o.type==='panel'?'Panel '+(o.label||''):o.type==='text'?'Text label':o.type==='obstruction'?'Keep-out area':'North arrow'}
    const history=[],future=[];
    const undo=root.querySelector('[data-layout-undo]'),redo=root.querySelector('[data-layout-redo]');
    function touch(){history.push(cloneLayout(layout));if(history.length>50)history.shift();future.length=0;if(touchedInput)touchedInput.value='1';input.dispatchEvent(new Event('input',{bubbles:true}));}
    function restore(from,to){if(!from.length)return;to.push(cloneLayout(layout));layout=from.pop();selected=null;touchedInput.value='1';render();input.dispatchEvent(new Event('input',{bubbles:true}));}
    undo?.addEventListener('click',()=>restore(history,future));redo?.addEventListener('click',()=>restore(future,history));
    function setStatus(msg,err){status.textContent=msg;status.classList.toggle('error',!!err);root.classList.toggle('has-layout-error',!!err);}
    function save(){syncGrid();if(input) input.value=JSON.stringify(layout);if(undo)undo.disabled=!history.length;if(redo)redo.disabled=!future.length;updatePreview();}
        function render(){syncGrid(); grid.innerHTML=''; Object.assign(grid.style,{width:(cols()*cellPx())+'px',height:(rows()*cellPx())+'px',backgroundSize:`${cellPx()}px ${cellPx()}px,${cellPx()}px ${cellPx()}px,${cellPx()*5}px ${cellPx()*5}px,${cellPx()*5}px ${cellPx()*5}px`}); const c=cell(); layout.objects.forEach(o=>{normalizePanel(o); const el=document.createElement('div'); el.className=`layout-object ${o.type}${o===selected?' selected':''}`; const footprintW=o.w*c.w, footprintH=o.h*c.h; Object.assign(el.style,{left:(o.x*c.w)+'px',top:(o.y*c.h)+'px',width:footprintW+'px',height:footprintH+'px'}); if(o.type==='panel'){const module=document.createElement('span'); module.className='layout-object-module'; const aspect=panelProfile(o.orientation).aspect; const availW=Math.max(24,footprintW-10), availH=Math.max(24,footprintH-10); let moduleW=availW, moduleH=moduleW/aspect; if(moduleH>availH){moduleH=availH; moduleW=moduleH*aspect;} Object.assign(module.style,{width:moduleW+'px',height:moduleH+'px'}); module.textContent=o.label||''; el.appendChild(module);} else {el.textContent=o.type==='arrow'?'↑':(o.type==='text'?(o.text||'Text'):(o.label||''));} el.addEventListener('pointerdown',e=>{select(o); drag={o,startX:e.clientX,startY:e.clientY,origX:o.x,origY:o.y,pointerId:e.pointerId,el:el}; if(typeof el.setPointerCapture==='function'){try{el.setPointerCapture(e.pointerId);}catch(error){if(window.console&&typeof window.console.warn==='function')window.console.warn('[Panel Layout Designer] Pointer capture unavailable',error);}} e.preventDefault();}); el.tabIndex=0;el.setAttribute('role','button');el.setAttribute('aria-label',labelFor(o));el.setAttribute('aria-pressed',String(o===selected));el.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();select(o);grid.focus();}});grid.appendChild(el);}); const msg=validateAll(); setStatus(msg||'Ready',!!msg); save(); }
    function select(o){selected=o; if(textInput) textInput.value=o?(o.type==='text'?(o.text||''):(o.label||'')):''; render();}
    function add(kind){const n=layout.objects.filter(o=>o.type==='panel').length+1; let o={id:kind+'_'+Date.now(),type:'panel',x:0,y:0,w:panelProfiles.portrait.w,h:panelProfiles.portrait.h,orientation:'portrait',visual_aspect_ratio:panelProfiles.portrait.aspect,label:String(n)}; if(kind==='panel-landscape') Object.assign(o,{w:panelProfiles.landscape.w,h:panelProfiles.landscape.h,orientation:'landscape',visual_aspect_ratio:panelProfiles.landscape.aspect}); if(kind==='text') o={id:'text_'+Date.now(),type:'text',x:6,y:0,w:6,h:2,text:'Main roof'}; if(kind==='obstruction') o={id:'obstruction_'+Date.now(),type:'obstruction',x:12,y:0,w:4,h:3,label:'Keep-out'}; if(kind==='arrow') o={id:'arrow_'+Date.now(),type:'arrow',x:Math.max(0,cols()-2),y:0,w:1,h:2,label:'North'}; for(let y=0;y<rows();y++)for(let x=0;x<cols();x++){o.x=x;o.y=y;if(valid(o)){layout.objects.push(o);select(o);setStatus('Placed on grid',false);return;}} setStatus('Space already occupied or outside the grid',true);}
    function resizeGrid(newCols,newRows){const old={columns:cols(),rows:rows()}; layout.grid.columns=clamp(newCols,limits.min,limits.max,old.columns); layout.grid.rows=clamp(newRows,limits.min,limits.max,old.rows); const msg=validateAll(); if(msg){layout.grid.columns=old.columns;layout.grid.rows=old.rows;} render();if(msg)setStatus('Some panels or labels are outside the smaller grid. Move them inside or increase the grid size before saving.',true);}
    function movePointer(e){if(!drag||e.pointerId!==drag.pointerId)return; if(!drag.recorded){touch();drag.recorded=true;} const c=cell(); const nx=Math.round(drag.origX+(e.clientX-drag.startX)/c.w), ny=Math.round(drag.origY+(e.clientY-drag.startY)/c.h); const old={x:drag.o.x,y:drag.o.y}; drag.o.x=Math.max(0,Math.min(cols()-drag.o.w,nx)); drag.o.y=Math.max(0,Math.min(rows()-drag.o.h,ny)); const blocked=invalidReason(drag.o);if(blocked)Object.assign(drag.o,old);render();if(blocked)setStatus(blocked,true);}
    function endPointer(e){if(!drag||e.pointerId!==drag.pointerId)return;if(drag.el&&typeof drag.el.releasePointerCapture==='function'){try{if(!drag.el.hasPointerCapture||drag.el.hasPointerCapture(drag.pointerId))drag.el.releasePointerCapture(drag.pointerId);}catch(error){/* Losing capture is harmless. */}}drag=null;}
    // Document listeners keep a drag usable when Safari declines pointer capture
    // or rendering replaces the original object element during the gesture.
    document.addEventListener('pointermove',movePointer); document.addEventListener('pointerup',endPointer); document.addEventListener('pointercancel',endPointer); root.querySelectorAll('[data-add-layout-item]').forEach(b=>b.addEventListener('click',()=>{touch();add(b.dataset.addLayoutItem)}));
    if(colInput)colInput.addEventListener('change',()=>{touch();resizeGrid(colInput.value,rows())}); if(rowInput)rowInput.addEventListener('change',()=>{touch();resizeGrid(cols(),rowInput.value)});
    const zoomIn=root.querySelector('[data-layout-zoom-in]'),zoomOut=root.querySelector('[data-layout-zoom-out]'),fit=root.querySelector('[data-layout-fit]'); if(zoomIn)zoomIn.addEventListener('click',()=>{layout.grid.editor_cell_px=cellPx()+2;render();}); if(zoomOut)zoomOut.addEventListener('click',()=>{layout.grid.editor_cell_px=cellPx()-2;render();}); if(fit)fit.addEventListener('click',()=>{const w=((scroller&&scroller.clientWidth)||720)-24;layout.grid.editor_cell_px=clamp(Math.floor(w/cols()),limits.minCell,limits.maxCell,18);render();});
    const clear=root.querySelector('[data-layout-clear]'),reset=root.querySelector('[data-layout-reset]'); if(clear)clear.addEventListener('click',()=>{touch();layout.objects=[];selected=null;render();}); if(reset)reset.addEventListener('click',()=>{touch();layout=cloneLayout(defaults);selected=null;render();});
    const remove=root.querySelector('[data-layout-delete]'),rotate=root.querySelector('[data-layout-rotate]'),duplicate=root.querySelector('[data-layout-duplicate]');
    if(remove)remove.addEventListener('click',()=>{if(!selected)return; touch(); layout.objects=layout.objects.filter(o=>o!==selected); selected=null; render();});
    if(rotate)rotate.addEventListener('click',()=>{if(!selected||selected.type!=='panel')return; touch(); const old={w:selected.w,h:selected.h,orientation:selected.orientation,visual_aspect_ratio:selected.visual_aspect_ratio}; selected.orientation=selected.orientation==='landscape'?'portrait':'landscape'; normalizePanel(selected); const blocked=!valid(selected);if(blocked)Object.assign(selected,old);render();if(blocked)setStatus('Rotating would overlap another item or leave the grid.',true);});
    if(duplicate)duplicate.addEventListener('click',()=>{if(!selected)return; touch(); const copy=cloneLayout(selected); copy.id=copy.type+'_'+Date.now(); copy.x+=copy.w; if(valid(copy)){layout.objects.push(copy);select(copy);return;} for(let y=0;y<rows();y++)for(let x=0;x<cols();x++){copy.x=x;copy.y=y;if(valid(copy)){layout.objects.push(copy);select(copy);return;}} setStatus('No clear space to duplicate this item.',true);});
    root.querySelectorAll('[data-layout-move]').forEach(b=>b.addEventListener('click',()=>{if(!selected)return; touch(); const old={x:selected.x,y:selected.y}; if(b.dataset.layoutMove==='up')selected.y--; if(b.dataset.layoutMove==='down')selected.y++; if(b.dataset.layoutMove==='left')selected.x--; if(b.dataset.layoutMove==='right')selected.x++; const blocked=invalidReason(selected);if(blocked)Object.assign(selected,old);render();if(blocked)setStatus(blocked,true);}));
    if(textInput)textInput.addEventListener('input',()=>{if(!selected)return; touch(); if(selected.type==='text')selected.text=textInput.value; else selected.label=textInput.value; render();});
    function addArray(){
      const nr=clamp(root.querySelector('[data-array-rows]').value,1,20,2),nc=clamp(root.querySelector('[data-array-columns]').value,1,20,4);
      const gap=clamp(root.querySelector('[data-array-gap]').value,0,5,1),orientation=root.querySelector('[data-array-orientation]').value,profile=panelProfile(orientation);
      const width=nc*profile.w+(nc-1)*gap,height=nr*profile.h+(nr-1)*gap,first=layout.objects.filter(o=>o.type==='panel').length+1;
      for(let y=0;y<=rows()-height;y++)for(let x=0;x<=cols()-width;x++){
        const batch=Array.from({length:nr*nc},(_,i)=>({id:'array_'+Date.now()+'_'+i,type:'panel',x:x+(i%nc)*(profile.w+gap),y:y+Math.floor(i/nc)*(profile.h+gap),w:profile.w,h:profile.h,orientation,visual_aspect_ratio:profile.aspect,label:String(first+i)}));
        if(batch.every(o=>valid(o))){touch();layout.objects.push(...batch);selected=batch[0];render();setStatus('Added '+batch.length+' panels. Review placement and service access.',false);return;}
      }
      setStatus('No clear space for this array. Reduce rows/columns or increase the grid.',true);
    }
    root.querySelector('[data-layout-add-array]')?.addEventListener('click',addArray);
    root.querySelector('[data-layout-focus]')?.addEventListener('click',e=>{
      const expanded=document.getElementById('quotationEditor')?.classList.toggle('layout-focus');
      e.currentTarget.textContent=expanded?'Compact workspace':'Expand workspace';e.currentTarget.setAttribute('aria-pressed',String(!!expanded));
    });
    root.querySelectorAll('[data-layout-view]').forEach(button=>button.addEventListener('click',()=>{
      const preview=button.dataset.layoutView==='preview';root.querySelector('[data-layout-preview]').hidden=!preview;root.querySelector('.panel-layout-main').hidden=preview;
      root.querySelectorAll('[data-layout-view]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));
    }));
    grid.addEventListener('keydown',e=>{
      if(e.target.matches('input,textarea'))return;
      const direction={ArrowUp:'up',ArrowDown:'down',ArrowLeft:'left',ArrowRight:'right'}[e.key];
      if(selected&&direction){e.preventDefault();root.querySelector('[data-layout-move="'+direction+'"]').click();grid.focus();}
      if(selected&&(e.key==='Delete'||e.key==='Backspace')){e.preventDefault();remove.click();grid.focus();}
      if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='z'){e.preventDefault();restore(e.shiftKey?future:history,e.shiftKey?history:future);grid.focus();}
    });
    function updatePreview(){
      const count=layout.objects.filter(o=>o.type==='panel').length;
      const zones=layout.objects.filter(o=>o.type==='obstruction').length;
      root.querySelector('[data-layout-count]').textContent=count+' panel'+(count===1?'':'s')+' · '+zones+' keep-out zone'+(zones===1?'':'s');
      const disabled=!selected;
      root.querySelectorAll('[data-layout-move],[data-layout-delete],[data-layout-duplicate]').forEach(b=>b.disabled=disabled);
      root.querySelector('[data-layout-rotate]').disabled=disabled||selected.type!=='panel';
      textInput.disabled=disabled;
      const selectedLabel=selected?(selected.type==='text'?(selected.text||''):(selected.label||'')):'';
      if(textInput.value!==selectedLabel)textInput.value=selectedLabel;
      const host=root.querySelector('[data-layout-preview-drawing]');host.replaceChildren();
      if(!layout.objects.length){host.textContent='Your customer preview will appear here when you add panels or rooftop markers.';return;}
      const ns='http://www.w3.org/2000/svg';
      const svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox','0 0 560 340');svg.setAttribute('role','img');svg.setAttribute('aria-label','Customer preview of proposed panel placement');
      function shape(tag,attrs,text,parent=svg){const el=document.createElementNS(ns,tag);Object.entries(attrs).forEach(([k,v])=>el.setAttribute(k,v));if(text)el.textContent=text;parent.append(el);return el;}
      shape('rect',{x:4,y:4,width:552,height:332,rx:14,fill:'#f3f7f5',stroke:'#d3e1db'});
      const minX=Math.max(0,Math.min(...layout.objects.map(o=>o.x))-2),minY=Math.max(0,Math.min(...layout.objects.map(o=>o.y))-2);
      const maxX=Math.min(cols(),Math.max(...layout.objects.map(o=>o.x+o.w))+2),maxY=Math.min(rows(),Math.max(...layout.objects.map(o=>o.y+o.h))+2);
      const cell=Math.min(500/(maxX-minX),240/(maxY-minY)),ox=30+(500-(maxX-minX)*cell)/2,oy=45+(240-(maxY-minY)*cell)/2;
      layout.objects.forEach(o=>{
        const x=ox+(o.x-minX)*cell,y=oy+(o.y-minY)*cell,w=o.w*cell,h=o.h*cell;
        if(o.type==='panel'){
          const aspect=panelProfile(o.orientation).aspect,vh=Math.min(h-2,(w-2)/aspect),vw=vh*aspect,vx=x+(w-vw)/2,vy=y+(h-vh)/2;
          shape('rect',{x:vx,y:vy,width:vw,height:vh,rx:3,fill:'#163f54',stroke:'#5c91a0','stroke-width':1});
          for(let j=1;j<4;j++)shape('path',{d:'M '+(vx+vw*j/4)+' '+(vy+2)+' V '+(vy+vh-2),stroke:'#7bb0bf','stroke-width':.4,opacity:.6});
          for(let j=1;j<7;j++)shape('path',{d:'M '+(vx+2)+' '+(vy+vh*j/7)+' H '+(vx+vw-2),stroke:'#7bb0bf','stroke-width':.4,opacity:.6});
          shape('text',{x:vx+vw/2,y:vy+vh/2+3,'text-anchor':'middle','font-size':10,fill:'#fff','font-family':'Arial'},o.label);
        }else if(o.type==='obstruction'){
          shape('rect',{x,y,width:w,height:h,rx:4,fill:'#fcebe5',stroke:'#c47b61','stroke-dasharray':'4 3'});shape('text',{x:x+4,y:y+14,'font-size':10,fill:'#8d4a35'},o.label);
        }else if(o.type==='arrow'){
          shape('path',{d:'M '+(x+w/2)+' '+(y+h)+' V '+y+' m -5 8 l 5 -8 l 5 8',fill:'none',stroke:'#996044','stroke-width':2});shape('text',{x:x+w/2,y:y-8,'text-anchor':'middle','font-size':12,fill:'#8d4a35'},'N');
        }else shape('text',{x:x+3,y:y+14,'font-size':12,fill:'#294a49'},o.text);
      });
      shape('text',{x:20,y:25,'font-size':10,fill:'#526c66','font-family':'Arial'},'PROPOSED PANEL PLACEMENT');
      shape('text',{x:20,y:320,'font-size':10,fill:'#526c66','font-family':'Arial'},'Schematic · Not to scale · Site verification required');host.append(svg);
    }
    if(form)form.addEventListener('submit',e=>{const msg=validateAll(); if(msg){e.preventDefault();setStatus(msg,true);}});
    render();
  }
  function safeParse(s){try{return JSON.parse(s||'{}')}catch(e){return null}}
})();
