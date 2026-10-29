"""Consistent, non-sensitive Storage failures for disk and SQLite incidents.

Uploads can fail before reaching the endpoint when Starlette rolls a multipart
UploadFile to the container's temporary filesystem. Treat that ENOSPC/EDQUOT
as storage exhaustion rather than leaking a generic HTTP 500.
"""
import errno
import logging
import sqlite3

from fastapi.responses import JSONResponse

logger = logging.getLogger("irlix.storage")

def install_storage_error_handlers(app):
    @app.exception_handler(OSError)
    async def storage_os_error(request, exc: OSError):
        # Keep details in private server logs, not in responses sent to users.
        logger.exception("Storage filesystem failure at %s", request.url.path)
        if exc.errno in (errno.ENOSPC, getattr(errno, "EDQUOT", -1)):
            return JSONResponse(status_code=507, content={
                "detail": "На сервере недостаточно места для загрузки файла."
            })
        if exc.errno in (errno.EACCES, errno.EROFS):
            return JSONResponse(status_code=503, content={
                "detail": "Файловое хранилище недоступно для записи."
            })
        return JSONResponse(status_code=500, content={
            "detail": "Сбой файлового хранилища. Обратитесь к администратору."
        })

    @app.exception_handler(sqlite3.Error)
    async def storage_sqlite_error(request, exc: sqlite3.Error):
        logger.exception("Storage metadata SQLite failure at %s", request.url.path)
        detail = str(exc).lower()
        if "database or disk is full" in detail or "disk i/o error" in detail:
            return JSONResponse(status_code=507, content={
                "detail": "Недостаточно места для сохранения метаданных файла."
            })
        return JSONResponse(status_code=503, content={
            "detail": "База метаданных файлов временно недоступна."
        })
