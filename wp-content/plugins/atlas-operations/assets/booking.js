(function () {
    'use strict';
    document.querySelectorAll('[data-atlas-booking]').forEach(function (form) {
        var button=form.querySelector('[data-atlas-reserve]'); var status=form.querySelector('[data-atlas-reserve-status]');
        var travelers=form.querySelector('[data-atlas-traveler-options]'); var needs=form.dataset.needsTravelers==='1';
        var learners=form.querySelector('[data-academy-learners]');
        if(learners){
            form.elements.quantity.readOnly=true;
            var updateLearners=function(event){
                if(event && event.target.matches('[data-academy-learner]') && event.target.checked) learners.querySelectorAll('[data-academy-learner]').forEach(function(input){ if(input!==event.target && input.dataset.learnerId===event.target.dataset.learnerId) input.checked=false; });
                form.elements.quantity.value=String(learners.querySelectorAll('[data-academy-learner]:checked').length);
            };
            learners.addEventListener('change',updateLearners); updateLearners();
        }
        var key=null; var hold=null; var signature='';
        if (needs) {
            button.disabled=true; status.textContent=atlasBooking.loadingTravelers;
            fetch(atlasBooking.travelersUrl,{credentials:'same-origin',headers:{'X-WP-Nonce':atlasBooking.restNonce}}).then(function(response){ if(!response.ok) throw new Error('unavailable'); return response.json(); }).then(function(records){
                if(!Array.isArray(records)) throw new Error('invalid');
                records.forEach(function(record){
                    if (!record || !record.id || !record.fields) return;
                    var label=document.createElement('label'); var input=document.createElement('input'); input.type='checkbox'; input.name='traveler'; input.value=record.id;
                    label.appendChild(input); label.appendChild(document.createTextNode(' '+(record.fields.name || ''))); travelers.appendChild(label);
                });
                status.textContent=records.length?atlasBooking.selectTravelers:atlasBooking.addTravelers;
                button.disabled=records.length===0;
            }).catch(function(){ status.textContent=atlasBooking.travelersUnavailable; });
        }
        form.addEventListener('submit', async function (event) {
            event.preventDefault(); if(button.disabled) return;
            var quantity=Number(form.elements.quantity.value);
            var refs=Array.prototype.map.call(form.querySelectorAll('input[name="traveler"]:checked'),function(input){return input.value;});
            if(!Number.isSafeInteger(quantity) || quantity<1 || quantity>50 || (needs && refs.length!==quantity)) { status.textContent=atlasBooking.invalid; return; }
            if(!window.crypto || !window.crypto.randomUUID) { status.textContent=atlasBooking.unavailable; return; }
            var options={};
            form.querySelectorAll('[data-atlas-booking-option]').forEach(function(input){
                var name=input.name.replace(/\[\]$/,'');
                if(input.type==='checkbox' && input.dataset.valueType==='bool') options[name]=input.checked;
                else if(/\[\]$/.test(input.name)){ options[name]=options[name]||[]; if(input.checked || input.tagName==='SELECT') options[name].push(input.value); }
                else if(input.type!=='radio' || input.checked) options[name]=input.value;
            });
            if(learners){
                options.academy_learners=Array.prototype.map.call(learners.querySelectorAll('[data-academy-learner]:checked'),function(input){
                    var selfEvidence=learners.querySelector('[data-academy-self-evidence]');
                    return {learner_id:Number(input.dataset.learnerId),authorization_id:input.dataset.authorization,authorization_version:Number(input.dataset.authorizationVersion),eligibility_reference:input.dataset.authorization?input.dataset.eligibility:(selfEvidence?selfEvidence.value:'')};
                });
            }
            var nextSignature=JSON.stringify([quantity,refs,options]);
            if(signature && signature!==nextSignature) { key=null; hold=null; }
            signature=nextSignature; key=key || window.crypto.randomUUID();
            button.disabled=true; status.textContent=atlasBooking.working;
            try {
                if(!hold) {
                    var response=await fetch(atlasBooking.holdUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':atlasBooking.restNonce,'Idempotency-Key':key},body:JSON.stringify({offer_id:Number(form.dataset.offer),offer_version:Number(form.dataset.version),quantity:quantity,participant_refs:refs,options:options})});
                    var result=await response.json();
                    if(!response.ok || !result.booking_id) { status.textContent=result.message || atlasBooking.error; button.disabled=false; return; }
                    hold=result;
                }
                if(form.dataset.cohort) {
                    var enrollmentResponse=await fetch(atlasBooking.cohortsUrl+encodeURIComponent(form.dataset.cohort)+'/enrollments',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':atlasBooking.restNonce},body:JSON.stringify(learners?{booking_id:hold.booking_id}:{booking_id:hold.booking_id,learner_id:atlasBooking.learnerId})});
                    var enrollment=await enrollmentResponse.json(); if(!enrollmentResponse.ok) { status.textContent=enrollment.message || atlasBooking.error; button.disabled=false; return; }
                }
                var cartResponse=await fetch(atlasBooking.cartUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Nonce':atlasBooking.storeNonce},body:JSON.stringify({id:Number(form.dataset.product),quantity:quantity,atlas_booking_id:hold.booking_id})});
                var cart=await cartResponse.json();
                if(!cartResponse.ok) { status.textContent=(cart.message || atlasBooking.error) + ' ' + atlasBooking.network; button.disabled=false; return; }
                window.location.assign(atlasBooking.checkoutUrl);
            } catch(err) { status.textContent=atlasBooking.network; button.disabled=false; }
        });
    });
}());
