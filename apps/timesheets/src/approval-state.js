// A reporting period sent to the client implies completed account approval.
export function clientPeriodApproved(source, clientId, date) {
  return (source?.client_reporting_periods || []).some(period =>
    Number(period.client_id) === Number(clientId)
    && period.period_start <= date && period.period_end >= date
    && ['ТШ на согласовании', 'ТШ согласованы', 'Акт на согласовании', 'Акт согласован', 'Счет оплачен'].includes(period.status));
}
