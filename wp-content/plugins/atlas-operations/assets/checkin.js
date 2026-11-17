(function(){
    'use strict';
    document.querySelectorAll('[data-atlas-checkin]').forEach(function(form){
        form.addEventListener('submit',async function(event){
            event.preventDefault(); var button=form.querySelector('button'); var status=form.querySelector('[role=status]'); button.disabled=true;
            var id=Number(form.elements.offer_id.value); var attendee=Number(form.elements.attendee_index.value);
            if(!Number.isSafeInteger(id)||id<1||!Number.isSafeInteger(attendee)||attendee<1){status.textContent=atlasCheckin.invalid;button.disabled=false;return;}
            try {
                var response=await fetch(atlasCheckin.base+id+'/checkins',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':atlasCheckin.nonce},body:JSON.stringify({booking_id:form.elements.booking_id.value,attendee_index:attendee})});
                var result=await response.json(); status.textContent=response.ok?atlasCheckin.success:(result.message||atlasCheckin.error);
            } catch(e){status.textContent=atlasCheckin.error;}
            button.disabled=false;
        });
    });
}());
