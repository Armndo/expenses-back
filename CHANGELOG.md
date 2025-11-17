## 0.3.0 (2025-11-17)

### BREAKING CHANGE

- must rename the endpoint used in the client from GET /expenses to GET /

### Feat

- **appcontroller**: added sum of expenses to categories object
- **category**: added color to model and created new migration for field
- **appcontroller**: added same query to get expenses_count on the cutoff date too for categories
- **appcontroller**: added count of expenses each month to categories
- session token lifetime extended from 1 hour to 1 day :)
- created AppController to move index functionality from ExpenseController
- **category**: added alias and order field to categories table, added sort by
- **expensescontroller**: added categories for index, store and update
- added categories model and foreign key to expenses model
- **cors**: added multiple FRONTEND_ORIGIN

### Fix

- changed empty endpoint to data to avoid CORS error
- **expensecontroller**: corrected offset of cutoff date for expenses
- **expensecontroller**: corrected not being able to change source of expense

## 0.2.0 (2025-03-19)

### Feat

- **expensecontroller**: coalescing cutoff date in query
- **expensecontroller**: implemented cutoff date in query
- implementing incomes
- **expensecontroller**: implemented correct ordering of expenses
- **expensecontroller**: implemented date from request for fetching expenses
- implemented CORS for local and live env, removed prefix for api routes
- **expensecontroller**: implemented instalment intervals
- **expensecontroller**: implemented instalment expenses in index, store and update functions
- implemented conventional commits with husky

### Fix

- **expensecontroller**: corrected query to match source's cutoff date
- **expensecontroller**: corrected query for instalment expenses, now working as expected
- added dateFormat in models in order to fix timestamp timezone insertion in database

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
