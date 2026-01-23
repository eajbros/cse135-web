# cse135-web

## Team Members
- Ethan Jenkins
- Nian-Nian Wang

## Grader Account
Username: grader
Password: 2026ILOVEEM

## Website Link:
[hknucsd-outreach.org](https://hknucsd-outreach.org/index.html)

## Automated Github Deployment

We configured automated deployment using GitHub Actions.

A workflow file (`deploy.yml`) is located in `.github/workflows/`. The workflow is triggered on every push to the `main` branch. When triggered, GitHub Actions connects to the DigitalOcean server via SSH and deploys the latest changes.

Deployment details:
- The workflow authenticates to the server using an SSH key.
- The SSH key pair was generated on the DigitalOcean server under the deployment user.
- The private key is stored securely as a GitHub Actions repository secret.
- The public key is authorized on the server for the deployment user.
- After authentication is successful, the workflow runs `git pull` in the site’s directory and reloads Apache.

## Website Login

This setup ensures that any changes pushed to the `main` branch are automatically deployed to the website.
website login:\
username: root\
password: 2026ILOVEEM

## Textual Content Compression

After enabling mod_deflate, the HTML response is compressed using gzip before being sent over. With content-encoding: gzip, the transferred HTML file size is smaller than the original.

## Removing Server Header

- First we set ServerTokens in Apache to full to allow it to display Server information and in our case, our custom tag
- We then installed and enabled the security2 module to be able to control our HTTP response headers
- Then, we configued ModSecurity, assing the line, SecServerSignature "CSE 135 server" to overwrite the defaule Server header.
- Then, restarting Apache we can now see Server: CSE 135 server in the header
