const normalizeDashboardLabel = () => {
  document.querySelectorAll('button, a, span').forEach((element) => {
    if (element.childElementCount > 0) return;
    if (element.textContent?.trim() === 'Дашборд сотрудника') {
      element.textContent = 'Дашборд';
    }
  });
};

const applyDashboardDensity = () => {
  if (document.getElementById('dashboard-density-fixes')) return;
  const style = document.createElement('style');
  style.id = 'dashboard-density-fixes';
  style.textContent = `
    #dashboard-page .card.dashboard-service-card {
      height: 180px;
    }

    #dashboard-page .dashboard-service-card > p {
      line-height: 1.35;
    }

    #dashboard-page .dashboard-service-roles {
      padding-top: 8px;
    }

    @media (max-width: 560px) {
      #dashboard-page .card.dashboard-service-card {
        height: auto;
        min-height: 170px;
      }
    }
  `;
  document.head.append(style);
};

const observer = new MutationObserver(normalizeDashboardLabel);
observer.observe(document.documentElement, { subtree: true, childList: true });

normalizeDashboardLabel();
applyDashboardDensity();
