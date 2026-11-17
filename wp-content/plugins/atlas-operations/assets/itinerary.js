(function () {
    'use strict';
    document.querySelectorAll('[data-atlas-tour-builder]').forEach(function (form) {
        var spec = {
            stops: [['city','City / شهر'],['country','Country / کشور'],['timezone','IANA zone / منطقه زمانی'],['arrival','Arrival UTC / ورود UTC'],['departure','Departure UTC / خروج UTC']],
            legs: [['origin','Origin / مبدأ'],['destination','Destination / مقصد'],['mode','Mode / نوع حمل‌ونقل'],['origin_timezone','Origin IANA zone / منطقه مبدأ'],['destination_timezone','Destination IANA zone / منطقه مقصد'],['depart_at','Departure UTC / خروج UTC'],['arrive_at','Arrival UTC / ورود UTC']],
            days: [['date','Date YYYY-MM-DD / تاریخ'],['type','Activity type / نوع فعالیت'],['description','Description / شرح'],['start','Start UTC / شروع UTC'],['end','End UTC / پایان UTC']]
        };
        Object.keys(spec).forEach(function (kind) {
            var field = form.querySelector('[data-atlas-structure="' + kind + '"]');
            if (!field) return;
            var label = form.querySelector('label[for="' + field.id + '"]');
            field.hidden = true; if (label) label.hidden = true;
            var section=document.createElement('section');
            var heading=document.createElement('h4'); heading.textContent=kind==='stops'?'Stops / توقف‌ها':kind==='legs'?'Transport legs / مسیرهای حمل‌ونقل':'Itinerary days / روزهای سفر'; section.appendChild(heading);
            var rows=document.createElement('div'); section.appendChild(rows);
            var add=document.createElement('button'); add.type='button'; add.textContent='Add / افزودن'; section.appendChild(add);
            function addRow() {
                var group=document.createElement('fieldset'); var legend=document.createElement('legend'); legend.textContent=heading.textContent; group.appendChild(legend);
                spec[kind].forEach(function (part) { var label=document.createElement('label'); label.textContent=part[1]; var input=document.createElement('input'); input.type='text'; input.dataset.key=part[0]; input.required=true; label.appendChild(input); group.appendChild(label); });
                var remove=document.createElement('button'); remove.type='button'; remove.textContent='Remove / حذف'; remove.addEventListener('click',function(){ group.remove(); }); group.appendChild(remove); rows.appendChild(group);
            }
            add.addEventListener('click',addRow); field.insertAdjacentElement('afterend',section);
            if (kind==='stops' || kind==='days') addRow();
            form.addEventListener('submit',function () {
                var values=[];
                rows.querySelectorAll('fieldset').forEach(function (row) { var v={}; row.querySelectorAll('input').forEach(function (input) { v[input.dataset.key]=input.value.trim(); }); if (kind==='days') { v={date:v.date,items:[{type:v.type,description:v.description,start:v.start,end:v.end}]}; } values.push(v); });
                field.value=JSON.stringify(values);
            });
        });
    });
}());
