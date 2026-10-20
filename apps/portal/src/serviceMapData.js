import { serviceGroups } from '../../../packages/ui/src/serviceCatalog.js';

// The published frontend inventory and URLs come from the shared launcher catalog.
// Relations and domain ownership remain explicitly curated and CI-checked.
const frontends = serviceGroups.flatMap(group => group.items)
  .filter(service => service.available && service.showInLauncher !== false && typeof service.href === 'string');
const frontendNodes = Object.fromEntries(frontends.map(service => [service.key, [service.label, service.href]]));

// Curated logical dependencies; never infer runtime reachability from this illustration.
export const contours = [
  {id:'ui',title:'Пользовательский контур',subtitle:'Веб-приложения',items:frontends.map(service => service.key)},
  {id:'business',title:'Бизнес-сервисы',subtitle:'API и бизнес-логика',items:['migration-api','migration-ops','employees-api','vacations-api','clients-api','timesheets-api','specialists-api','equipment-api','recruitment-api','cv-api']},
  {id:'platform',title:'Платформенное ядро и интеграции',subtitle:'Общие компоненты',items:['platform-core','resource-monitor','keycloak','rabbitmq','redis']},
  {id:'data',title:'Данные и хранение',subtitle:'Общая инфраструктура',items:['postgres','files','volumes']},
];
export const nodes = {
 ...frontendNodes,
 'employees-api':['Employees API'],'vacations-api':['Vacations API'],'clients-api':['Clients API'],
 'timesheets-api':['Timesheets API'],'specialists-api':['Specialists API'],
 'equipment-api':['Equipment API'],'recruitment-api':['Recruitment API'],
 'cv-api':['CV API'],'migration-api':['Migration API'],'migration-ops':['Migration Ops / rollback'],
 'platform-core':['Platform Core'],'resource-monitor':['Resource Monitor / collector'],keycloak:['Keycloak'],rabbitmq:['RabbitMQ'],redis:['Redis'],
 postgres:['PostgreSQL'],files:['Файлы и документы'],volumes:['Docker volumes'],
};
export const links = [
 // Actual browser-facing contracts: the dashboard uses Employees access and domain attention APIs.
 ['dashboard','employees-api'],['dashboard','vacations-api'],['dashboard','timesheets-api'],
 // System hosts monitoring, map and embedded migration console; no standalone /migration/ UI.
 ['system','employees-api'],['system','platform-core'],['system','migration-api'],
 ['employees','employees-api'],['vacations','vacations-api'],
 ['clients','clients-api'],['timesheets','timesheets-api'],['specialists','specialists-api'],
 ['equipment','equipment-api'],['recruitment','recruitment-api'],['cv-converter','cv-api'],
 ['migration-api','migration-ops'],['migration-ops','volumes'],
 ['employees-api','platform-core'],['vacations-api','employees-api'],['clients-api','employees-api'],
 ['timesheets-api','clients-api'],['timesheets-api','employees-api'],['timesheets-api','vacations-api'],
 ['specialists-api','employees-api'],['equipment-api','employees-api'],['recruitment-api','employees-api'],
 ['migration-api','employees-api'],['migration-api','vacations-api'],['migration-api','clients-api'],
 ['platform-core','keycloak'],['employees-api','rabbitmq'],['platform-core','postgres'],
 ['employees-api','postgres'],['clients-api','postgres'],['timesheets-api','postgres'],['vacations-api','postgres'],
 ['specialists-api','postgres'],['equipment-api','postgres'],['migration-api','postgres'],
 ['platform-core','redis'],['platform-core','resource-monitor'],['resource-monitor','volumes'],
 ['cv-api','volumes'],['migration-api','files'],
];
// Cross-lane business domains: one horizontal contour spans Frontend and Backend.
// Shared infrastructure remains in its own vertical lane.
export const domainContours = [
 {id:'platform',label:'Платформа',items:['dashboard','system','design-system','platform-core','migration-api','migration-ops']},
 {id:'people',label:'Сотрудники',items:['employees','vacations','employees-api','vacations-api']},
 {id:'clients',label:'Клиентский контур',items:['clients','timesheets','clients-api','timesheets-api']},
 {id:'it',label:'IT',items:['specialists','equipment','specialists-api','equipment-api']},
 {id:'recruitment',label:'HR',items:['recruitment','cv-converter','recruitment-api','cv-api']},
];
// Browser route security is separate from backend resource authorization.
export const canViewServiceMap = access => Array.isArray(access?.roles) && access.roles.some(
 role=>['platform-admin'].includes(String(role).trim().toLowerCase().replaceAll('_','-'))
);

/** Short, user-facing summaries only; not a runtime discovery or SLA inventory. */
export const serviceDescriptions = {
 ...Object.fromEntries(frontends.map(service => [service.key, service.description || service.label])),
 dashboard:'Главная точка входа в платформу и навигация между внутренними сервисами.',
 employees:'Интерфейс сотрудников, оргструктуры и управления персоналом.',
 vacations:'Интерфейс заявок на отпуска и другие официальные отсутствия.',
 clients:'Клиенты, запросы, позиции, попытки подключений и отчётные периоды.',
 timesheets:'Заполнение и согласование учёта рабочего времени по проектам.',
 specialists:'Работа с данными специалистов и их профессиональными характеристиками.',
 equipment:'Учёт техники и её выдачи сотрудникам.',
 recruitment:'Рабочий интерфейс направления подбора персонала.',
 'cv-converter':'Подготовка и конвертация резюме.',
 system:'Административная Система: ресурсный монитор, интерактивная карта и консоль миграции (/system/migration/).',
 'design-system':'Витрина компонентов и визуальных правил единой дизайн-системы.',
 'platform-core':'Платформенные API, общие настройки и инфраструктурные функции.',
 'employees-api':'API сотрудников, оргструктуры, ролей и организационного доступа.',
 'vacations-api':'API отсутствий и процессов их согласования.',
 'clients-api':'API клиентов, запросов, подключений и отчётных периодов.',
 'timesheets-api':'API рабочего времени и подтверждений таймшитов.',
 'specialists-api':'API данных специалистов и их профилей.',
 'equipment-api':'API учёта техники и назначений.',
 'recruitment-api':'API направления подбора персонала.',
 'cv-api':'API преобразования резюме и настроек конвертера.',
 'migration-api':'API управления импортом, сверками и запуском миграций; подключается к Migration Ops.',
 'migration-ops':'Изолированные операции миграции, диагностики, snapshot и rollback.',
 'resource-monitor':'Фоновый сборщик host/Docker-метрик без HTTP listener. Platform Core читает его snapshot и историю.',
 keycloak:'Централизованная аутентификация и управление учётными записями.',
 rabbitmq:'Шина асинхронных сообщений между компонентами.',
 redis:'Общий инфраструктурный компонент для кеширования.',
 postgres:'Общий сервер PostgreSQL; бизнес-сервисы используют изолированные схемы.',
 files:'Хранилище документов и файловых артефактов.',
 volumes:'Постоянные Docker-тома для состояния сервисов.',
};
