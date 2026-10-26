// Module-level, in-memory cache shared by all Clients workflow tabs.
// A single request returns only warning levels; no personal absence details.
let expiry=0;
let inflight=null;
export function loadClientAbsenceAlerts(fetcher=fetch, now=Date.now()) {
 if (!inflight || now >= expiry) {
   expiry=now+180000;
   inflight=fetcher('/api/vacations/client-service-absence-alerts', {
     headers:{Accept:'application/json'},
   }).then(async response=>{
     if (!response.ok) throw new Error('Service unavailable');
     const payload=await response.json();
     if (!payload?.data || typeof payload.data!=='object' || Array.isArray(payload.data)) throw new Error('Invalid payload');
     return payload.data;
   }).catch(()=>{
     // Retry on next screen load instead of persisting a transient failure.
     inflight=null;
     expiry=0;
     return {};
   });
 }
 return inflight;
}
export function resetClientAbsenceAlertsForTest() { inflight=null;expiry=0; }
