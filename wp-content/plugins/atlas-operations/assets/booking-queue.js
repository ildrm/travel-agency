(function(){
    'use strict';
    document.querySelectorAll('[data-atlas-booking-queue]').forEach(function(panel){
        var select=panel.querySelector('select'),output=panel.querySelector('[data-queue-output]'),status=panel.querySelector('[role=status]'),previous=panel.querySelector('[data-prev-page]'),next=panel.querySelector('[data-next-page]'),pageStatus=panel.querySelector('[data-page-status]'),refresh=panel.querySelector('[data-refresh]'),pageNumber=1,limit=25;
        function cell(row,text){var td=document.createElement('td');td.textContent=String(text);row.appendChild(td);return td;}
        async function load(){
            status.textContent=atlasBookingQueue.loading;previous.disabled=true;next.disabled=true;refresh.disabled=true;select.disabled=true;
            try{
                var response=await fetch(atlasBookingQueue.listUrl+'?status='+encodeURIComponent(select.value)+'&page='+pageNumber+'&limit='+limit,{credentials:'same-origin',headers:{'X-WP-Nonce':atlasBookingQueue.nonce}});
                var data=await response.json();if(!response.ok||!Array.isArray(data.items))throw new Error('queue');
                output.replaceChildren();pageStatus.textContent='Page / صفحه '+pageNumber;previous.disabled=pageNumber===1;next.disabled=data.items.length!==limit;if(!data.items.length){status.textContent=atlasBookingQueue.empty;return;}
                var table=document.createElement('table'),thead=document.createElement('thead'),head=document.createElement('tr'),body=document.createElement('tbody');
                atlasBookingQueue.headers.forEach(function(label){var th=document.createElement('th');th.scope='col';th.textContent=label;head.appendChild(th);});thead.appendChild(head);table.appendChild(thead);table.appendChild(body);
                data.items.forEach(function(booking){
                    var row=document.createElement('tr');row.dataset.bookingId=booking.booking_id;row.dataset.bookingState=booking.status;cell(row,booking.booking_id);cell(row,booking.status);cell(row,booking.status==='paid_pending_confirmation'||booking.status==='confirmed'?'verified':'Review payment separately / پرداخت جداگانه بررسی شود');
                    cell(row,Number.isSafeInteger(booking.amount_irr)?String(booking.amount_irr)+' IRR':atlasBookingQueue.unsafeAmount);var action=cell(row,'');
                    if(booking.status==='paid_pending_confirmation'){
                        var form=document.createElement('form'),label=document.createElement('label'),reason=document.createElement('input'),button=document.createElement('button');label.textContent=atlasBookingQueue.reason;reason.name='reason';reason.required=true;reason.maxLength=190;label.appendChild(reason);button.type='submit';button.textContent=atlasBookingQueue.confirm;form.appendChild(label);form.appendChild(button);action.appendChild(form);var key=null,signature='';
                        form.addEventListener('submit',async function(event){event.preventDefault();if(!reason.value.trim()){status.textContent=atlasBookingQueue.reasonRequired;return;}if(!window.crypto||!window.crypto.randomUUID){status.textContent=atlasBookingQueue.error;return;}if(signature!==reason.value.trim()){signature=reason.value.trim();key=window.crypto.randomUUID();}button.disabled=true;
                            try{var result=await fetch(atlasBookingQueue.base+encodeURIComponent(booking.booking_id)+'/confirm',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':atlasBookingQueue.nonce,'Idempotency-Key':key},body:JSON.stringify({reason:signature})});var json=await result.json();if(!result.ok){status.textContent=json.message||atlasBookingQueue.error;button.disabled=false;return;}status.textContent=atlasBookingQueue.confirmed;form.remove();row.children[1].textContent=json.status;row.dataset.bookingState=json.status;}catch(error){status.textContent=atlasBookingQueue.error;button.disabled=false;}
                        });
                    }
                    body.appendChild(row);
                });output.appendChild(table);status.textContent='';
            }catch(error){status.textContent=atlasBookingQueue.error;previous.disabled=pageNumber===1;}finally{refresh.disabled=false;select.disabled=false;}
        }
        select.addEventListener('change',function(){pageNumber=1;load();});refresh.addEventListener('click',load);previous.addEventListener('click',function(){if(pageNumber>1){pageNumber--;load();}});next.addEventListener('click',function(){pageNumber++;load();});load();
    });
}());
