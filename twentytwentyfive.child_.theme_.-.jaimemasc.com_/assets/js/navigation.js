(() => {
	'use strict';
	const header = document.querySelector('.mohfam-header');
	if (!header) return;
	const requestedService = new URLSearchParams(window.location.search).get('service');
	if (requestedService) {
		document.querySelectorAll('select[name="service"]').forEach((select) => {
			if ([...select.options].some((option) => option.value === requestedService)) select.value = requestedService;
		});
	}
	const menu = header.querySelector('.mohfam-menu-toggle');
	const nav = header.querySelector('.mohfam-nav');
	const submenuButton = header.querySelector('.mohfam-submenu-toggle');
	const dropdown = header.querySelector('.mohfam-nav__dropdown');
	if (!menu || !nav || !submenuButton || !dropdown) return;
	const mobile = window.matchMedia('(max-width: 1100px)');
	menu.hidden = false;
	submenuButton.hidden = false;
	header.classList.add('has-navigation');
	let hoverTimer;
	let openedByHover = false;
	const openSubmenu = (fromHover = false) => {
		window.clearTimeout(hoverTimer);
		if (!fromHover || submenuButton.getAttribute('aria-expanded') !== 'true') openedByHover = fromHover;
		submenuButton.setAttribute('aria-expanded', 'true');
		submenuButton.setAttribute('aria-label', 'Hide service links');
		dropdown.classList.add('is-open');
	};
	const closeSubmenu = () => {
		window.clearTimeout(hoverTimer);
		openedByHover = false;
		submenuButton.setAttribute('aria-expanded', 'false');
		submenuButton.setAttribute('aria-label', 'Show service links');
		dropdown.classList.remove('is-open');
	};
	const closeMenu = (restoreFocus = false) => {
		menu.setAttribute('aria-expanded', 'false');
		header.classList.remove('is-menu-open');
		closeSubmenu();
		if (restoreFocus && mobile.matches) menu.focus();
	};
	menu.addEventListener('click', () => {
		const open = menu.getAttribute('aria-expanded') !== 'true';
		menu.setAttribute('aria-expanded', String(open));
		header.classList.toggle('is-menu-open', open);
		if (!open) closeSubmenu();
	});
	submenuButton.addEventListener('click', () => {
		const open = submenuButton.getAttribute('aria-expanded') !== 'true' || openedByHover;
		if (open) openSubmenu();
		else closeSubmenu();
	});
	dropdown.addEventListener('pointerenter', (event) => {
		if (!mobile.matches && event.pointerType === 'mouse') openSubmenu(true);
	});
	dropdown.addEventListener('pointerleave', (event) => {
		if (!mobile.matches && event.pointerType === 'mouse' && !dropdown.contains(document.activeElement)) {
			hoverTimer = window.setTimeout(closeSubmenu, 140);
		}
	});
	dropdown.addEventListener('focusout', (event) => {
		if (!dropdown.contains(event.relatedTarget)) closeSubmenu();
	});
	header.addEventListener('keydown', (event) => {
		if (event.key !== 'Escape') return;
		if (submenuButton.getAttribute('aria-expanded') === 'true') {
			closeSubmenu();
			submenuButton.focus();
		} else if (menu.getAttribute('aria-expanded') === 'true') {
			closeMenu(true);
		}
	});
	nav.addEventListener('click', (event) => {
		if (event.target.closest('a')) closeMenu();
	});
	document.addEventListener('click', (event) => {
		if (!header.contains(event.target)) closeMenu();
	});
	header.addEventListener('focusout', (event) => {
		if (!header.contains(event.relatedTarget)) closeMenu();
	});
	mobile.addEventListener('change', () => closeMenu());
	const path = window.location.pathname.replace(/\/$/, '') || '/';
	if (path.startsWith('/services/')) header.querySelector('.mohfam-nav__dropdown-toggle').classList.add('is-active');
	nav.querySelectorAll('a').forEach((link) => {
		const url = new URL(link.href);
		if (url.origin === window.location.origin && !url.hash && (url.pathname.replace(/\/$/, '') || '/') === path) {
			link.setAttribute('aria-current', 'page');
		}
	});
})();
