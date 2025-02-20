# Domainsbot Name Suggestion
Provides domain name suggestions based on user queries using the DomainsBot API

## Installation

```bash
git clone https://github.com/getnamingo/fossbilling-domainsbot
mv fossbilling-domainsbot/Domainsbot /var/www/modules/
```

- Go to Extensions > Overview in the admin panel and activate "Domainsbot Name Suggestion".

- Obtain your authentication token from DomainsBot and enter it in the module settings.

## Usage Instructions  

This module integrates seamlessly with **Namingo Registrar** or **FOSSBilling** using the **Tide theme**. After enabling the module, users can access domain name suggestions directly from the **Order New Domain** page by clicking the **"Domain Suggestions"** button. This will retrieve domain name ideas via the DomainsBot API, allowing users to register their chosen domain with ease.  

## Current Status  

- The module is **stable**, though some **unused code** needs cleanup.  
- **Inactive extensions** are currently displayed but **should be hidden** in future updates.  
- A **caching mechanism** is missing but highly recommended to improve performance—**contributions in this area would be valuable**.  

This module enhances the domain search experience by suggesting creative and available domain names based on user input. It is designed to streamline the registration process and help customers discover the best domain names for their needs.

## License

Apache License 2.0