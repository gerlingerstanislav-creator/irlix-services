// The Gantt view needs a complete, year-scoped snapshot. Keep it separate from
// scroll-driven Kanban paging and never publish a partially fetched year.
export async function fetchAllReportingYearPages(fetchPage, isCurrent = () => true) {
  const periods = [];
  let page = 1;
  while (isCurrent()) {
    const result = await fetchPage(page);
    if (!isCurrent()) return null;
    const rows = result?.data?.reportingPeriods;
    if (!Array.isArray(rows)) throw new Error('Не удалось получить отчётные периоды выбранного года.');
    periods.push(...rows);
    if (!result.meta?.has_more) return periods;
    // A broken pagination response must not be treated as a complete year.
    if (!rows.length) throw new Error('Загрузка отчётных периодов прервана: сервер вернул пустую страницу.');
    page += 1;
  }
  return null;
}
