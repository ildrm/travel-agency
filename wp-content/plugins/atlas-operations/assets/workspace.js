/* Offer a keyboard-accessible shortcut through the panels rendered for this user. */
document.querySelectorAll('.atlas-dashboard').forEach(function (workspace) {
    const summaries = Array.from(workspace.querySelectorAll(':scope > details > summary'));
    if (summaries.length < 4) return;

    const fa = document.documentElement.lang.startsWith('fa');
    const form = document.createElement('form');
    form.className = 'atlas-workspace-jump';
    const label = document.createElement('label');
    label.textContent = fa ? 'رفتن به بخش' : 'Jump to a task';
    const select = document.createElement('select');
    summaries.forEach(function (summary, index) {
        select.add(new Option(summary.textContent.trim(), String(index)));
    });
    const button = document.createElement('button');
    button.type = 'submit';
    button.textContent = fa ? 'باز کردن بخش' : 'Open task';
    label.append(select);
    form.append(label, button);
    workspace.querySelector('h2').after(form);
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const summary = summaries[select.selectedIndex];
        summary.parentElement.open = true;
        summary.focus();
        summary.scrollIntoView({block: 'start'});
    });
});
