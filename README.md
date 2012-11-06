The ExtElliptics is a Yii Framework extension that provides methods to work with the Elliptics Network
that is a fault tolerant key/value storage developed by Evgeniy Polyakov (Yandex).
See http://www.ioremap.net/projects/elliptics/ for more info.

Requirements
------------

- Yii 1.1.*

Installation
------------

 - Unpack all files under your project 'extensions' folder
 - Include your new extension into your project main.php configuration file:

        'components' => array(
            'elliptics' => array(
                'class' => 'application.extensions.Elliptics.ExtElliptics',
                'privateServerAddress' => '127.0.0.1',
                'publicServerAddress' => 'localhost',
                'writePort' => '8080',
                'readPort' => '80',
                'monitoringPort' => '81',
            ),
            ...
        )

 - Configure your Elliptics Proxy so it can accept custom upload and direct requests:

        <handlers>
            ...
            <handler pool="read" port="[%port_read%]">
                 <component name="elliptics-proxy"/>
            </handler>
            <handler pool="write" port="[%port_write%]">
                <component name="elliptics-proxy"/>
            </handler>
            ...
        </handlers>

 - Note, that all files uploaded by extension will have a timestamp in their metadata so you need
   to make requests for file content like this:

        http://localhost/?direct&name=filename.txt&embed_timestamp=1

   You can easily hide this by your proxy web-server settings. Nginx configuration example:

        upstream elproxy {
            server unix:/var/run/elliptics/default.sock;
        }
        server {
            listen       80;
            location @elliptics {
                include        fastcgi_params;
                fastcgi_pass   elproxy;
            }
            location ~ ^/([^/?]*\.[^/?]*(\?.*)?)$ {
                set $file $1;
                rewrite ^ /?direct&name=$file&embed_timestamp=1 break;
                try_files @elliptics;
            }
        }

 - Enjoy!

Usage:
-------

 To upload file:

    Yii::app()->elliptics->upload('/path/to/file/1.txt', 'custom_name.txt');

 To get uploaded file content:

    $fileContent = Yii::app()->elliptics->get('custom_name.txt');

 To delete file:

    Yii::app()->elliptics->delete('custom_name.txt');

Changelog:
-------

- 1.1   Initial release
