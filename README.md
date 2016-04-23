# Customized Froxlor

The server administration software for your needs.
Developed by experienced server administrators, this panel simplifies the effort of managing your hosting platform.

This froxlor version contains the following additional features:
* Dynamic DNS (`dynamic-dns` branch and `mail_autoconfiguration` branch)
* Automatic MUA configuration (`mail_autoconfiguration` branch)

## Updating from official Froxlor

1. Make sure this repository has the same or a higher version than your Froxlor installation.
2. Follow normal Froxlor udpate procedure but use the files from this repository.

## Updating from customized Froxlor

1. If the value of `db_version` setting is 201604080 or 201604160 change it to 201603150.
2. If the value of `db_version` setting is 201608261 or 201608262 change it to 201608260.
2. Open `install/updates/froxlor/0.9/update_0.9.inc.php`, search for `Adding new dynamic domain field` and comment out all lines starting with `Database::query` up to but excluding the line starting with `updateToDbVersion`.
3. Open `install/updates/froxlor/0.9/update_0.9.inc.php`, search for `Adding mail autoconfiguration fields` and comment out all lines up to but excluding the line starting with `updateToDbVersion`.
4. Follow normal Froxlor udpate procedure but use the files from this repository.

## Installation

### Fast install
1. Ensure that your webserver serves /var/www
2. Extract froxlor into /var/www
3. Point your browser to http://[ip-of-webserver]/froxlor
4. Follow the installer
5. Login as administrator
6. Adjust "System > Settings" according to your needs
7. Choose your distribution under "System > Configuration"
8. Follow the steps for your services
9. Have fun!

### Detailed installation
https://github.com/Froxlor/Froxlor/wiki/Install-froxlor-from-tarball

## Help

You may find help in the following places:

### IRC

froxlor may be found on freenode.net, channel #froxlor:
irc://chat.freenode.net/froxlor

### Forum

The community is located on https://forum.froxlor.org/

### Wiki

More documentation may be found in the froxlor - wiki:
https://github.com/Froxlor/Froxlor/wiki

## License

May be found in COPYING

## Downloads

### Tarball
https://files.froxlor.org/releases/froxlor-latest.tar.gz [MD5](https://files.froxlor.org/releases/froxlor-latest.tar.gz.md5) [SHA1](https://files.froxlor.org/releases/froxlor-latest.tar.gz.sha1)

### Debian repository

[HowTo](https://github.com/Froxlor/Froxlor/wiki/Install-froxlor-on-debian)

/etc/apt/sources.list.d/froxlor.list
> deb http://debian.froxlor.org {wheezy|jessie} main

### Gentoo repository

[HowTo](https://github.com/Froxlor/Froxlor/wiki/Install-froxlor-on-gentoo)

https://files.froxlor.org/gentoo/repositories.xml

## Contributing

[see here](.github/CONTRIBUTING.md)
