// Standalone behavioral test fixtures; not a Vue/browser implementation.
// Models encode contracts selected after following the skill's workflow.

/** Case 1: data table; last-response-wins and dirty draft preservation. */
export function createTableModel({ load, save, initialQuery = { search: '', page: 1 } }) {
  let seq = 0;
  let rows = [];
  let query = { ...initialQuery };
  const drafts = new Map();
  const state = { get rows() { return rows; }, get query() { return query; }, get drafts() { return drafts; }, loading: false, error: null };
  async function refresh(nextQuery = query) {
    query = { ...nextQuery };
    const own = ++seq;
    state.loading = true;
    state.error = null;
    try {
      const data = await load({ ...query });
      if (own === seq) rows = data;
    } catch (e) {
      if (own === seq) state.error = e;
    } finally {
      if (own === seq) state.loading = false;
    }
  }
  function edit(id, patch) {
    drafts.set(id, { ...(drafts.get(id) || {}), ...patch });
  }
  async function commit(id) {
    if (!drafts.has(id)) return;
    const patch = { ...drafts.get(id) };
    await save(id, patch);
    if (drafts.has(id) && Object.entries(patch).every(([k,v]) => drafts.get(id)[k] === v)) drafts.delete(id);
    // Keep concurrent later edits; real Vue implementations must reconcile versioned server response.
  }
  function serializeQuery() {
    const params = new URLSearchParams();
    if (query.search) params.set('search', query.search);
    if (query.page !== 1) params.set('page', String(query.page));
    return params.toString();
  }
  return { state, refresh, edit, commit, serializeQuery };
}

/** Case 2: 401 retry once after single-flight renew; no token persistence. */
export function createApiSession({ getToken, renew, request, logout }) {
  let renewing = null;
  async function renewOnce() {
    if (!renewing) renewing = Promise.resolve().then(renew).finally(() => { renewing = null; });
    return renewing;
  }
  async function get(path) {
    const perform = () => request(path, getToken());
    let result = await perform();
    if (result.status !== 401) return result;
    try { await renewOnce(); } catch (err) { await logout(); throw err; }
    result = await perform();
    if (result.status === 401) await logout();
    return result;
  }
  return { get };
}

/** Case 3: stable keyed selection + cached derived rows for large tables. */
export function createRowView(rows) {
  const records = [...rows];
  const selection = new Set();
  let cache = { search: null, result: null, dataVersion: 0 };
  let version = 1;
  function filter(search) {
    if (cache.search === search && cache.dataVersion === version) return cache.result;
    const needle = search.toLocaleLowerCase();
    const result = records.filter(r => r.label.toLocaleLowerCase().includes(needle));
    cache = { search, result, dataVersion: version };
    return result;
  }
  function replace(nextRows) { records.splice(0, records.length, ...nextRows); version++; }
  function select(id) { selection.add(id); }
  function selectedIds() { return [...selection]; }
  const rowKey = row => row.id;
  return { filter, replace, select, selectedIds, rowKey };
}
