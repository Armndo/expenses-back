## 0.1.0 (2025-02-24)

### Feat

- implemented commitizen init for bumping
- **config**: added local database connection
- **expensecontroller**: added count of expenses to sources, changed destroy function
- added logout endpoint and function, added ordering of expenses
- removed softdeletes (they were useless) on both models and migrations
- **usercontroller**: disabled revoke tokens
- **expense**: foce casting amount to float
- **expensecontroller**: implemented store, update and destroy expenses functions and endpoints
- **expensecontroller**: implemented date range for index internally
- implemented querying for regular expenses by current month
- created sources controller with basic authenticated index route
- created models for sources, expenses and incomes and added their relationships
- created migrations for sources, expenses and incomes
- implemented expiration of tokens, removed web routes
- implemented passport for authentication, changed user migration and model, added UserController
- installed api routes

### Fix

- changed token timeout to 1 hour
