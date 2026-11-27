(() => {
    'use strict';
    const form=document.querySelector('[data-direct-return-form]');
    if(!form)return;
    const mode=form.elements.return_mode,inputs=[...form.querySelectorAll('[data-return-quantity]')];
    const partial=new Map(inputs.map(input=>[input,mode.value==='total'?'0':input.value]));
    mode.addEventListener('change',()=>{
        for(const input of inputs){
            if(mode.value==='total'){partial.set(input,input.value);input.value=input.dataset.returnRemaining;input.readOnly=true;}
            else{input.value=partial.get(input)||'0';input.readOnly=false;}
        }
    });
})();
