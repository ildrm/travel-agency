(function () {
    'use strict';
    document.querySelectorAll('[data-atlas-traveler-form]').forEach(function(form){
        var status=form.querySelector('[role=status]'); var button=form.querySelector('button'); var key=null;
        form.addEventListener('submit',async function(event){
            event.preventDefault(); if(button.disabled) return;
            if(!window.crypto || !window.crypto.randomUUID) { status.textContent=atlasTraveler.unavailable; return; }
            key=key || window.crypto.randomUUID(); button.disabled=true; status.textContent=atlasTraveler.saving;
            var data={name:form.elements.name.value,birth_date:form.elements.birth_date.value,guardian_name:form.elements.guardian_name.value,guardian_attested:form.elements.guardian_attested.checked};
            try {
                var response=await fetch(atlasTraveler.url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':atlasTraveler.nonce,'Idempotency-Key':key},body:JSON.stringify(data)});
                var result=await response.json();
                if(!response.ok) { status.textContent=result.message || atlasTraveler.error; button.disabled=false; return; }
                status.textContent=atlasTraveler.saved; form.reset(); key=null; button.disabled=false;
            } catch(err) { status.textContent=atlasTraveler.retry; button.disabled=false; }
        });
    });
}());
