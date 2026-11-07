# Contribuer à TAL Messaging

Merci de l'intérêt que vous portez à **TAL Messaging** ! Les contributions sont les bienvenues pour améliorer la plateforme.

## Comment contribuer ?

1. **Créer une issue** : Avant de commencer un développement important, discutez-en via une *Issue* pour expliquer ce que vous souhaitez modifier ou ajouter.
2. **Cloner et brancher** :
   * Forkez le dépôt et clonez-le sur votre machine.
   * Créez une branche dédiée pour votre fonctionnalité ou correction :
     ```bash
     git checkout -b feature/nom-de-votre-fonctionnalite
     ```
3. **Respecter les standards du projet** :
   * Architecture **MVC modulaire sans framework** (PHP 8.1+).
   * Isolation stricte des tenants via `TenantRepository`.
   * Styles avec **Tailwind CSS** (compilé localement via npm).
   * Respect des règles de sécurité (pas de CDN externe, requêtes préparées, gestion des tokens TOTP et sessions sécurisées).
4. **Tester votre code** :
   * Exécutez les tests unitaires avant de proposer vos modifications :
     ```bash
     php tests/run.php
     ```
5. **Soumettre une Pull Request (PR)** :
   * Poussez votre branche sur votre fork et ouvrez une Pull Request vers la branche `main` du dépôt officiel en décrivant clairement vos modifications.
