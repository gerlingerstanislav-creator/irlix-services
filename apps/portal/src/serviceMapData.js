// Curated logical dependencies; never infer runtime reachability from this illustration.
export const contours = [
  {id:'ui',title:'Пользовательский контур',subtitle:'Веб-приложения',items:['dashboard','employees','vacations','clients','timesheets','specialists','equipment','recruitment','cv-converter','migration','design-system']},
  {id:'business',title:'Бизнес-сервисы',subtitle:'API и бизнес-логика',items:['employees-api','vacations-api','clients-api','timesheets-api','specialists-api','equipment-api','recruitment-api','cv-api','migration-api']},
  {id:'platform',title:'Платформенное ядро и интеграции',subtitle:'Общие компоненты',items:['platform-core','keycloak','rabbitmq','redis']},
  {id:'data',title:'Данные и хранение',subtitle:'Общая инфраструктура',items:['postgres','files','volumes']},
];
export const nodes = {
 dashboard:['Dashboard','/'],employees:['Сотрудники','/employees/'],vacations:['Отсутствия','/vacations/'],clients:['Клиенты','/clients/'],
 timesheets:['Таймшиты','/timesheets/'],specialists:['Специалисты','/specialists/'],equipment:['Учёт техники','/equipment/'],
 recruitment:['Recruitment','/recruitment/'],'cv-converter':['CV конвертер','/cv-converter/'],
 migration:['Перенос данных','/migration/'],'design-system':['Design System','/design-system/'],
 'employees-api':['Employees API'],'vacations-api':['Vacations API'],'clients-api':['Clients API'],
 'timesheets-api':['Timesheets API'],'specialists-api':['Specialists API'],
 'equipment-api':['Equipment API'],'recruitment-api':['Recruitment API'],
 'cv-api':['CV API'],'migration-api':['Migration API'],
 'platform-core':['Platform Core'],keycloak:['Keycloak'],rabbitmq:['RabbitMQ'],redis:['Redis'],
 postgres:['PostgreSQL'],files:['Файлы и документы'],volumes:['Docker volumes'],
};
export const links = [
 ['dashboard','platform-core'],['employees','employees-api'],['vacations','vacations-api'],
 ['clients','clients-api'],['timesheets','timesheets-api'],['specialists','specialists-api'],
 ['equipment','equipment-api'],['recruitment','recruitment-api'],['cv-converter','cv-api'],
 ['migration','migration-api'],
 ['employees-api','platform-core'],['vacations-api','employees-api'],['clients-api','employees-api'],
 ['timesheets-api','clients-api'],['timesheets-api','employees-api'],['timesheets-api','vacations-api'],
 ['specialists-api','employees-api'],['equipment-api','employees-api'],['recruitment-api','employees-api'],
 ['migration-api','employees-api'],['migration-api','vacations-api'],['migration-api','clients-api'],
 ['platform-core','keycloak'],['employees-api','rabbitmq'],['platform-core','postgres'],
 ['employees-api','postgres'],['clients-api','postgres'],['timesheets-api','postgres'],['vacations-api','postgres'],
 ['specialists-api','postgres'],['equipment-api','postgres'],['migration-api','postgres'],
 ['platform-core','redis'],['cv-api','volumes'],['migration-api','files'],
];
// Architectural overlays follow the actual visual ordering of nodes in each lane.
// Each node belongs to exactly one contour; these do not alter positions/edges.
export const mapContours = [
 {id:'platform-ui',label:'Платформа',lane:'ui',items:['dashboard']},
 {id:'people-ui',label:'Сотрудники и отсутствия',lane:'ui',items:['employees','vacations']},
 {id:'clients-ui',label:'Клиентский контур',lane:'ui',items:['clients','timesheets']},
 {id:'it-ui',label:'IT',lane:'ui',items:['specialists','equipment']},
 {id:'recruitment-ui',label:'Recruitment',lane:'ui',items:['recruitment','cv-converter']},
 {id:'tools-ui',label:'Платформенные инструменты',lane:'ui',items:['migration','design-system']},
 {id:'platform-api',label:'Платформа',lane:'business',items:['platform-core']},
 {id:'people-api',label:'Сотрудники и отсутствия',lane:'business',items:['employees-api','vacations-api']},
 {id:'clients-api-contour',label:'Клиентский контур',lane:'business',items:['clients-api','timesheets-api']},
 {id:'it-api',label:'IT',lane:'business',items:['specialists-api','equipment-api']},
 {id:'recruitment-api-contour',label:'Recruitment',lane:'business',items:['recruitment-api','cv-api']},
 {id:'tools-api',label:'Платформенные инструменты',lane:'business',items:['migration-api']},
 {id:'platform-infra',label:'Платформенная инфраструктура',lane:'infra',items:['keycloak','rabbitmq','redis']},
 {id:'storage-infra',label:'Хранение данных',lane:'infra',items:['postgres','files','volumes']},
];
// Browser route security is separate from backend resource authorization.
export const canViewServiceMap = access => Array.isArray(access?.roles) && access.roles.some(
 role=>['platform-admin','platform-tester'].includes(String(role).trim().toLowerCase().replaceAll('_','-'))
);
