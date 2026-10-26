/**
 * Build a stable, precomputed snapshot of the management table.
 * Cell calculations run only when the inputs change, not during hover, selection
 * or other unrelated Vue renders.
 */
export function buildManagementGroups(clientOptions, rows, dates, getCell) {
  const byClient = new Map();
  for (const row of rows) {
    const id = String(row.clientId);
    if (!byClient.has(id)) byClient.set(id, []);
    byClient.get(id).push(row);
  }

  return clientOptions.flatMap((client) => {
    const clientRows = byClient.get(String(client.value));
    if (!clientRows?.length) return [];

    return [{
      clientId: String(client.value),
      label: client.label,
      rows: clientRows.map((row) => {
        const cells = dates.map((date) => getCell(row, date));
        const activeCells = cells.filter((cell) => cell.active);
        const approvedCount = activeCells.filter((cell) => cell.final).length;
        return {
          ...row,
          cells,
          monthlyHours: cells.reduce((sum, cell) => sum + cell.hours, 0),
          monthApproval: {
            all: activeCells.length > 0 && approvedCount === activeCells.length,
            any: approvedCount > 0,
          },
        };
      }),
    }];
  });
}
