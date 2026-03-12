# BotLocal

Chatbot local en PHP para XAMPP que usa Ollama como motor de IA y soporta tres modos:

- Conversacion general con memoria en sesion.
- Preguntas sobre un tema especifico usando archivos locales.
- Preguntas sobre bases de datos en modo de solo lectura.

## Requisitos

- XAMPP con PHP 8.2 o superior.
- Ollama ejecutandose localmente en `http://127.0.0.1:11434`.
- Un modelo descargado en Ollama. La configuracion inicial usa `qwen3:30b`.

## Estructura

- `index.php`: interfaz web.
- `api/chat.php`: endpoint principal del chat.
- `api/state.php`: carga historial y estado inicial.
- `api/reset.php`: limpia la conversacion de un modo.
- `src/`: clases del backend.
- `knowledge/`: documentos usados por el modo tematico.
- `config/config.php`: configuracion principal.

## Configuracion basica

Edita `config/config.php` si quieres cambiar el modelo o la URL de Ollama:

```php
'app' => [
    'ollama_url' => 'http://127.0.0.1:11434',
    'model' => 'qwen3:30b',
]
```

## Modo tema especifico

Agrega archivos `.md`, `.txt`, `.csv` o `.xlsx` dentro de `knowledge/`. El bot los cargara como contexto base.

Ejemplos:

- Catalogo de productos.
- Procedimientos internos.
- Documentacion de un modulo de tu sistema.
- Reportes exportados desde Excel.

Notas sobre archivos tabulares:

- `.csv` y `.xlsx` se convierten a texto estructurado por hojas, columnas y filas.
- `.xls` antiguo no esta soportado en esta version.
- Para mejores resultados, usa encabezados claros en la primera fila.

## Modo base de datos

Activa la configuracion en `config/config.php`:

```php
'database' => [
    'enabled' => true,
    'driver' => 'mysql', // mysql | pgsql | sqlite
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'tu_base',
    'schema' => 'public', // usado en PostgreSQL
    'sqlite_path' => '', // usado en SQLite
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'table_whitelist' => ['usuarios', 'ventas'],
    'schema_max_tables' => 8,
    'schema_max_columns_per_table' => 12,
    'schema_include_related' => true,
    'planner_history_messages' => 1,
    'plan_cache_enabled' => true,
    'planner_model' => 'gpt-oss:20b',
    'use_llm_summary' => false,
    'max_rows' => 25,
]
```

Notas importantes:

- El bot solo acepta consultas `SELECT` o `WITH`.
- Si defines `table_whitelist`, el modelo solo vera esas tablas en el esquema.
- El backend ya soporta `MySQL`, `PostgreSQL` y `SQLite`.
- Para bases grandes, no envia todo el esquema al modelo: selecciona solo las tablas mas relevantes para la pregunta.
- Tambien puede cachear el plan SQL de preguntas repetidas para responder mucho mas rapido en consultas similares.
- La respuesta final se basa en los resultados devueltos por la consulta.
- Para acelerar el modo DB, la configuracion actual usa `gpt-oss:20b` solo como planner SQL y resume resultados en PHP sin una segunda llamada al LLM.

Ejemplos de driver:

- MySQL/MariaDB: usa `driver => 'mysql'`, `host`, `port`, `database`, `username`, `password`.
- PostgreSQL: usa `driver => 'pgsql'`, `host`, `port`, `database`, `schema`, `username`, `password`.
- SQLite: usa `driver => 'sqlite'` y `sqlite_path`.

## Base de prueba RH

El proyecto incluye un seed para una base MySQL de Recursos Humanos en `database/rh_demo.sql`.

Incluye tablas de:

- `departamentos`
- `puestos`
- `empleados`
- `vacaciones`
- `asistencias`

La configuracion actual de `config/config.php` ya apunta a `botlocal_rh_demo`.

Para recrearla localmente:

```powershell
php scripts/setup_rh_demo_db.php
```

## Uso

1. Asegurate de que Apache y Ollama esten activos.
2. Abre `http://localhost/BotLocal/`.
3. Selecciona el modo.
4. Escribe tu mensaje.

## Siguiente paso recomendado

Si quieres mejorar la precision para documentos largos o multiples temas, el siguiente paso natural es agregar embeddings locales y recuperacion por similitud antes de llamar al modelo.
# Bot_Local
