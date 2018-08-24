Connect to Jira via Curl

### Install
For development you should install this library like follow:

Start at your ILIAS root directory 
```bash
mkdir -p Customizing/global/plugins/Libraries/  
cd Customizing/global/plugins/Libraries/  
git clone git@git.studer-raimann.ch:ILIAS/Plugins/JiraCurl.git JiraCurl
```

### Usage

#### Composer
First add the follow to your `composer.json` file:
```json
"require": {
  "srag/jiracurl": "^0.1.3"
},
```

If your plugin should support ILIAS 5.2 or earlier you need to require `ilCurlConnection` like follow in your `composer.json` file:
```json
"autoload": {
    "classmap": [
      "../../../../../../../Services/WebServices/Curl/classes/class.ilCurlConnection.php",
```
May you need to adjust the relative `ilCurlConnection` path

And run a `composer install`.

If you deliver your plugin, the plugin has it's own copy of this library and the user doesn't need to install the library.

Hint: Because of multiple autoloaders of plugins, it could be, that different versions of this library exists and suddenly your plugin use an old version of an other plugin! So you should keep up to date your plugin with `composer update`.
