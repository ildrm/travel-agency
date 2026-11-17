(function(){
    'use strict';
    document.querySelectorAll('[data-atlas-inventory]').forEach(function(form){
        var specs={
            resources:[['record_type','Record type: unit_type or vehicle / نوع رکورد'],['record_id','Record ID / شناسه'],['min_stay','Minimum nights / حداقل شب','number'],['turnaround_minutes','Turnaround minutes / زمان آماده‌سازی','number']],
            stay:[['resource_id','Resource ID / شناسه منبع'],['check_in','Check-in YYYY-MM-DD / ورود'],['check_out','Check-out YYYY-MM-DD / خروج'],['quantity','Rooms / تعداد','number'],['reference','Unique reference / شناسه یکتا'],['request_id','Request ID, optional / شناسه درخواست، اختیاری','optional']],
            vehicle:[['resource_id','Resource ID / شناسه منبع'],['start_utc','Start UTC / شروع UTC'],['end_utc','End UTC / پایان UTC'],['kind','rental, transfer or maintenance / نوع'],['reference','Unique reference / شناسه یکتا'],['request_id','Request ID, optional / شناسه درخواست، اختیاری','optional']],
            capacity:[['resource_id','Resource ID / شناسه منبع'],['capacity','Capacity / ظرفیت','number'],['min_stay','Minimum nights / حداقل شب','number']],
            blackout:[['resource_id','Resource ID / شناسه منبع'],['start_date','Start YYYY-MM-DD / شروع'],['end_date','End YYYY-MM-DD / پایان'],['blocked','Blocked / مسدود','bool']],
            start:[['allocation_id','Allocation ID / شناسه تخصیص']],
            'return':[['allocation_id','Allocation ID / شناسه تخصیص'],['actual_utc','Return UTC / زمان بازگشت UTC'],['inspection','Inspection / بررسی'],['disputed','Disputed / مورد اختلاف','bool']],
            release:[['allocation_id','Allocation ID / شناسه تخصیص']],
            'resolve-dispute':[['allocation_id','Allocation ID / شناسه تخصیص'],['reason','Reason / دلیل']]
        };
        var select=form.querySelector('select'); var fields=form.querySelector('[data-inventory-fields]'); var status=form.querySelector('[role=status]');
        function render(){fields.replaceChildren(); specs[select.value].forEach(function(def){var label=document.createElement('label'); label.appendChild(document.createTextNode(def[1])); var input=document.createElement('input'); input.name=def[0]; input.type=def[2]==='number'?'number':def[2]==='bool'?'checkbox':'text'; if(def[2]==='number') input.min='0'; if(def[2]!=='optional' && def[2]!=='bool') input.required=true; label.appendChild(input); fields.appendChild(label);});}
        select.addEventListener('change',render); render();
        form.addEventListener('submit',async function(e){e.preventDefault(); var button=form.querySelector('button');button.disabled=true; status.textContent=atlasInventory.working;var body={}; specs[select.value].forEach(function(def){var item=form.elements[def[0]];if(def[2]==='bool') body[def[0]]=item.checked;else if(def[2]==='number') body[def[0]]=Number(item.value);else if(item.value) body[def[0]]=item.value;});try{var response=await fetch(atlasInventory.base+select.value,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':atlasInventory.nonce},body:JSON.stringify(body)});var result=await response.json();status.textContent=response.ok?JSON.stringify(result):result.message||atlasInventory.error;}catch(err){status.textContent=atlasInventory.error;}button.disabled=false;});
    });
}());
