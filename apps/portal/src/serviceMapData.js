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
// Cross-lane business domains: one horizontal contour spans Frontend and Backend.
// Shared infrastructure remains in its own vertical lane.
export const domainContours = [
 {id:'platform',label:'Платформа',items:['dashboard','platform-core']},
 {id:'people',label:'Сотрудники и отсутствия',items:['employees','vacations','employees-api','vacations-api']},
 {id:'clients',label:'Клиентский контур',items:['clients','timesheets','clients-api','timesheets-api']},
 {id:'it',label:'IT',items:['specialists','equipment','specialists-api','equipment-api']},
 {id:'recruitment',label:'Recruitment',items:['recruitment','cv-converter','recruitment-api','cv-api']},
 {id:'tools',label:'Платформенные инструменты',items:['migration','design-system','migration-api']},
];
// Browser route security is separate from backend resource authorization.
export const canViewServiceMap = access => Array.isArray(access?.roles) && access.roles.some(
 role=>['platform-admin','platform-tester'].includes(String(role).trim().toLowerCase().replaceAll('_','-'))
);

/** Short, user-facing summaries only; not a runtime discovery or SLA inventory. */
export const serviceDescriptions = {
 dashboard:'Главная точка входа в платформу и навигация между внутренними сервисами.',
 employees:'Интерфейс сотрудников, оргструктуры и управления персоналом.',
 vacations:'Интерфейс заявок на отпуска и другие официальные отсутствия.',
 clients:'Клиенты, запросы, позиции, попытки подключений и отчётные периоды.',
 timesheets:'Заполнение и согласование учёта рабочего времени по проектам.',
 specialists:'Работа с данными специалистов и их профессиональными характеристиками.',
 equipment:'Учёт техники и её выдачи сотрудникам.',
 recruitment:'Рабочий интерфейс направления подбора персонала.',
 'cv-converter':'Подготовка и конвертация резюме.',
 migration:'Административный интерфейс переноса данных из прежней системы.',
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
 'migration-api':'API управления переносом данных и операциями миграции.',
 keycloak:'Централизованная аутентификация и управление учётными записями.',
 rabbitmq:'Шина асинхронных сообщений между компонентами.',
 redis:'Общий инфраструктурный компонент для кеширования.',
 postgres:'Общий сервер PostgreSQL; бизнес-сервисы используют изолированные схемы.',
 files:'Хранилище документов и файловых артефактов.',
 volumes:'Постоянные Docker-тома для состояния сервисов.',
};
