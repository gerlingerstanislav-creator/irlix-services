const drawerRules = [
  { selector: '.client-card-drawer', minWidth: 720 },
  { selector: '.legal-drawer', minWidth: 420 },
];

function findResizableDrawer(x, y) {
  for (const element of document.elementsFromPoint(x, y)) {
    for (const rule of drawerRules) {
      const drawer = element.matches?.(rule.selector) ? element : element.closest?.(rule.selector);
      if (!drawer) continue;
      const rect = drawer.getBoundingClientRect();
      if (Math.abs(x - rect.left) <= 8) return { drawer, minWidth: rule.minWidth };
    }
  }
  return null;
}

function startResize(event) {
  if (window.innerWidth <= 720 || event.button !== 0) return;
  const target = findResizableDrawer(event.clientX, event.clientY);
  if (!target) return;

  event.preventDefault();
  const { drawer, minWidth } = target;
  const startX = event.clientX;
  const startWidth = drawer.getBoundingClientRect().width;
  document.body.classList.add('irlix-drawer-resizing');

  const move = (moveEvent) => {
    const maxWidth = Math.floor(window.innerWidth * 0.9);
    const nextWidth = startWidth + (startX - moveEvent.clientX);
    drawer.style.width = `${Math.max(Math.min(minWidth, maxWidth), Math.min(maxWidth, nextWidth))}px`;
  };
  const stop = () => {
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', stop);
    window.removeEventListener('pointercancel', stop);
    document.body.classList.remove('irlix-drawer-resizing');
  };

  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', stop);
  window.addEventListener('pointercancel', stop);
}

document.addEventListener('pointerdown', startResize);
