(function(){
    'use strict';
    document.querySelectorAll('[data-atlas-materials]').forEach(function(button){
        button.addEventListener('click',async function(){
            var output=button.parentElement.querySelector('[data-materials-output]'); button.disabled=true; output.textContent=atlasMaterials.loading;
            try {
                var response=await fetch(atlasMaterials.base+encodeURIComponent(button.dataset.atlasMaterials)+'/materials',{credentials:'same-origin',headers:{'X-WP-Nonce':atlasMaterials.nonce}});
                var data=await response.json(); if(!response.ok) { output.textContent=atlasMaterials.unavailable; button.disabled=false; return; }
                output.replaceChildren(); var heading=document.createElement('h4'); heading.textContent=(atlasMaterials.language==='fa' && data.course_title_fa)||data.course_title_en||''; output.appendChild(heading);
                if(!data.materials.length){var empty=document.createElement('p');empty.textContent=atlasMaterials.empty;output.appendChild(empty);}
                data.materials.forEach(function(item){var title=document.createElement('h5');title.textContent=item.title;var body=document.createElement('p');body.textContent=item.body;output.appendChild(title);output.appendChild(body);});
            }catch(e){output.textContent=atlasMaterials.unavailable;} button.disabled=false;
        });
    });
}());
