# Installing Docker

memex runs as one Docker container. Install Docker for your system, check that it works,
then go back to [Run it](README.md#run-it).

## macOS

Docker Desktop runs on the current macOS and the two before it, on Apple silicon or Intel.

1. Download it for your Mac's chip, which Apple menu › *About This Mac* names:
   [Apple silicon](https://desktop.docker.com/mac/main/arm64/Docker.dmg) or
   [Intel](https://desktop.docker.com/mac/main/amd64/Docker.dmg).
2. Open `Docker.dmg` and drag Docker into Applications.
3. Open Docker from Applications and accept its agreement.

With [Homebrew](https://brew.sh), this replaces the first two steps:

```bash
brew install --cask docker-desktop
```

## Windows

Docker Desktop runs on 64-bit Windows 10 (22H2) or Windows 11 (23H2 or later), x86 or ARM,
with hardware virtualisation on in the BIOS or UEFI.

1. Open PowerShell as administrator, install WSL 2, and restart the computer:

   ```powershell
   wsl --install
   ```

2. Install Docker Desktop, in PowerShell again:

   ```powershell
   winget install -e --id Docker.DockerDesktop
   ```

   or with the installer from
   [Docker's Windows page](https://docs.docker.com/desktop/setup/install/windows-install/),
   leaving *Use WSL 2* selected.
3. Open Docker Desktop, and accept its agreement if it asks.

Run memex's commands in PowerShell, and type `curl.exe` where they say `curl`.

## Linux

On Ubuntu, Debian, Fedora, RHEL or CentOS, Docker's script installs Docker Engine. For
another distribution, follow its page in [Docker's list](https://docs.docker.com/engine/install/).
Ubuntu Desktop comes without `curl`; install it first with `sudo apt install curl`.

```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
```

Then start Docker with the computer, and let your user run `docker` without `sudo`:

```bash
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
```

Sign out and back in for the second line to take effect. Membership of the `docker` group
is as good as root on that computer; to keep it to root, skip the second line and put `sudo`
before every `docker` command.

## Check it works

```bash
docker run --rm hello-world
```

It prints *Hello from Docker!*. On macOS and Windows, Docker Desktop has to be open for
`docker` to answer.

## Start with the computer

memex comes back after a restart once Docker does. On Linux, the `systemctl` line above
sees to that. On macOS and Windows, turn on Docker Desktop › Settings › General ›
*Start Docker Desktop when you sign in to your computer*, which is off until you do.
