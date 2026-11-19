/* Without JavaScript the header keeps all navigation links visible. */
document.querySelectorAll('.atlas-header-inner').forEach(function (header, index) {
    const groups = Array.from(header.querySelectorAll('.atlas-main-navigation, .atlas-utility'));
    if (!groups.length) return;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'atlas-menu-toggle';
    button.textContent = document.documentElement.lang.startsWith('fa') ? 'فهرست' : 'Menu';
    groups.forEach(function (group, part) {
        if (!group.id) group.id = 'atlas-header-nav-' + index + '-' + part;
    });
    button.setAttribute('aria-controls', groups.map(group => group.id).join(' '));
    groups[0].before(button);
    const mobile = window.matchMedia('(max-width: 640px)');
    function show(expanded) {
        button.hidden = !mobile.matches;
        button.setAttribute('aria-expanded', String(expanded));
        groups.forEach(group => { group.hidden = mobile.matches && !expanded; });
    }
    function reset() {
        const focusInside = groups.some(group => group.contains(document.activeElement));
        show(!mobile.matches);
        if (mobile.matches && focusInside) button.focus();
    }
    button.addEventListener('click', () => show(button.getAttribute('aria-expanded') !== 'true'));
    header.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && mobile.matches && button.getAttribute('aria-expanded') === 'true') {
            show(false);
            button.focus();
        }
    });
    mobile.addEventListener('change', reset);
    reset();
});
