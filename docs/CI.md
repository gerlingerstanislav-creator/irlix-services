# CI

Pull requests to `main` validate Docker Compose and build the complete stack. Pushes to `main` repeat validation and then deploy the stack to the stand over SSH, followed by internal and external HTTP health checks.
