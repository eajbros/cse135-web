# cse135-web

## Automated Deployment

We configured automated deployment using GitHub Actions.

A workflow file (`deploy.yml`) is located in `.github/workflows/`. The workflow is triggered on every push to the `main` branch. When triggered, GitHub Actions connects to the DigitalOcean server via SSH and deploys the latest changes.

Deployment details:
- The workflow authenticates to the server using an SSH key.
- The SSH key pair was generated on the DigitalOcean server under the deployment user.
- The private key is stored securely as a GitHub Actions repository secret.
- The public key is authorized on the server for the deployment user.
- After authentication is successful, the workflow runs `git pull` in the site’s directory and reloads Apache.

This setup ensures that any changes pushed to the `main` branch are automatically deployed to the website.
website login:\
username: root\
password: 2026ILOVEEM
