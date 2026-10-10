const drawerRules = [
  { selector: '.client-card-drawer', minWidth: 520 },
  { selector: '.legal-drawer', minWidth: 420 },
];

function findResizableDrawer(x, y) {
  // Only the topmost visible drawer can be resized. elementsFromPoint() also
  // returns drawers underneath modal overlays and must not be used here.
  const element = document.elementFromPoint(x, y);
  if (!element) return null;
  for (const rule of drawerRules) {
    const drawer = element.matches?.(rule.selector) ? element : element.closest?.(rule.selector);
    if (!drawer) continue;
    // The client card becomes inactive while a legal entity drawer is open.
    if (drawer.matches('.client-card-drawer') && drawer.querySelector('.nested-overlay')) return null;
    const rect = drawer.getBoundingClientRect();
    if (Math.abs(x - rect.left) <= 8) return { drawer, minWidth: rule.minWidth };
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
