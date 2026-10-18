(function () {
  'use strict';
  function safeHTML(value) {
    const doc=new DOMParser().parseFromString(value,'text/html');
    const allowed=new Set(['P','BR','UL','OL','LI','STRONG','EM','B','I','U','TABLE','THEAD','TBODY','TR','TD','TH','A']);
    doc.querySelectorAll('script,style,iframe,object').forEach(el=>el.remove());
    [...doc.body.querySelectorAll('*')].reverse().forEach(el=>{
      if(!allowed.has(el.tagName)){el.replaceWith(...el.childNodes);return;}
      const href=el.tagName==='A'?el.getAttribute('href'):null;
      [...el.attributes].forEach(attr=>el.removeAttribute(attr.name));
      if(href&&/^(https?:\/\/|mailto:)/i.test(href)) {el.setAttribute('href',href);el.setAttribute('rel','noopener noreferrer');}
    });
    return doc.body.innerHTML;
  }
  document.querySelectorAll('textarea[data-rich-text],textarea[name^="block_"],textarea[name^="ann_"]').forEach((source,index)=>{
    const wrapper=document.createElement('div');wrapper.className='quotation-rich-editor';
    const toolbar=document.createElement('div');toolbar.className='rich-toolbar';toolbar.setAttribute('role','toolbar');toolbar.setAttribute('aria-label','Text formatting');
    const editor=document.createElement('div');editor.contentEditable='true';editor.className='rich-content';editor.setAttribute('role','textbox');editor.setAttribute('aria-multiline','true');
    editor.setAttribute('aria-label',source.previousElementSibling?.textContent||source.name);editor.innerHTML=safeHTML(source.value);
    const preview=document.createElement('div');preview.className='rich-preview';preview.hidden=true;
    const mode=source.parentElement.querySelector('[data-ann-mode]');
    function sync(){source.value=editor.innerHTML;if(mode)mode.value='custom';source.dispatchEvent(new Event('input',{bubbles:true}));}
    [['Bold','bold'],['Italic','italic'],['Bullets','insertUnorderedList'],['Numbered list','insertOrderedList'],['Undo','undo']].forEach(([label,command])=>{
      const button=document.createElement('button');button.type='button';button.textContent=label;
      button.addEventListener('mousedown',e=>e.preventDefault());button.addEventListener('click',()=>{editor.focus();document.execCommand(command,false,null);sync();});toolbar.append(button);
    });
    const toggle=document.createElement('button');toggle.type='button';toggle.textContent='Preview';toggle.setAttribute('aria-expanded','false');
    toggle.addEventListener('click',()=>{preview.hidden=!preview.hidden;preview.innerHTML=editor.innerHTML;toggle.setAttribute('aria-expanded',String(!preview.hidden));});toolbar.append(toggle);
    if(mode){
      const reset=document.createElement('button');reset.type='button';reset.textContent='Reset to saved template';
      const applyMode=()=>{
        if(mode.value==='inherit'){editor.innerHTML=safeHTML(source.dataset.template||'');source.value=editor.innerHTML;}
        const custom=mode.value==='custom';editor.contentEditable=String(custom);editor.setAttribute('aria-readonly',String(!custom));
        editor.hidden=mode.value==='hide';toolbar.querySelectorAll('button').forEach(b=>{if(b!==reset&&b!==toggle)b.disabled=!custom;});
        source.dispatchEvent(new Event('input',{bubbles:true}));
      };
      reset.addEventListener('click',()=>{mode.value='inherit';applyMode();});toolbar.append(reset);mode.addEventListener('change',applyMode);
      // Do not mark the form dirty simply by initializing an inherited section.
      if(mode.value==='inherit'){editor.innerHTML=safeHTML(source.dataset.template||'');source.value=editor.innerHTML;}
      editor.contentEditable=String(mode.value==='custom');editor.hidden=mode.value==='hide';
      editor.setAttribute('aria-readonly',String(mode.value!=='custom'));
      toolbar.querySelectorAll('button').forEach(b=>{if(b!==reset&&b!==toggle)b.disabled=mode.value!=='custom';});
      source.form?.querySelector('[name=template_set_id]')?.addEventListener('change',e=>{
        source.dataset.template=window.quotationTemplateBlocks?.[e.target.value]?.blocks?.[mode.dataset.annMode]||'';
        if(mode.value==='inherit')applyMode();
      });
    }
    editor.addEventListener('input',sync);
    // Treat pasted content as plain text; formatting can then be applied with the toolbar.
    editor.addEventListener('paste',e=>{e.preventDefault();document.execCommand('insertText',false,e.clipboardData.getData('text/plain'));sync();});
    wrapper.append(toolbar,editor,preview);source.after(wrapper);source.hidden=true;
    source.form?.addEventListener('submit',()=>{if(!mode||mode.value==='custom')source.value=editor.innerHTML;});
  });
})();
