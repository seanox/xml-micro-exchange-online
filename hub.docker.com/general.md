# Quick reference

__Maintained by:__  
[Seanox Software Solutions](https://seanox.com)

__Where to get help:__  
[Seanox Software Solutions](https://seanox.com/contact)


# Supported tags and respective `Dockerfile` links

__Note:__ The following only lists the current main tags with reference to the
implementation and the corresponding Dockerfiles. For specific version tags, see
the Dockerfile in the corresponding branches,

- [php](https://github.com/seanox/xml-micro-exchange-php/blob/master/Dockerfile)  
  [versions](https://github.com/seanox/xml-micro-exchange-php/tags)
- [js](https://github.com/seanox/xml-micro-exchange-js/blob/master/Dockerfile)  
  [versions](https://github.com/seanox/xml-micro-exchange-js/tags)
- [java](https://github.com/seanox/xml-micro-exchange-java/blob/master/Dockerfile)  
  [versions](https://github.com/seanox/xml-micro-exchange-java/tags)


# Quick reference (cont.)

__Where to file issues__ (depending on the project)__:__  
https://github.com/seanox/xml-micro-exchange-php/issues  
https://github.com/seanox/xml-micro-exchange-js/issues  
https://github.com/seanox/xml-micro-exchange-java/issues

__Where to find getting started__ (depending on the project)__:__  
https://github.com/seanox/xml-micro-exchange-js/blob/main/manual/getting-started.md  
https://github.com/seanox/xml-micro-exchange-js/blob/main/manual/getting-started.md  
https://github.com/seanox/xml-micro-exchange-js/blob/main/manual/getting-started.md

__Where to find the manual__ (depending on the project)__:__  
https://github.com/seanox/xml-micro-exchange-php/blob/master/manual/README.md  
https://github.com/seanox/xml-micro-exchange-js/blob/master/manual/README.md  
https://github.com/seanox/xml-micro-exchange-java/blob/master/manual/README.md

__Where to find the sources__ (depending on the project)__:__  
https://github.com/seanox/xml-micro-exchange-php  
https://github.com/seanox/xml-micro-exchange-js  
https://github.com/seanox/xml-micro-exchange-java


# What is XMEX?

The origin of the project is the desire for an easily accessible place for data
exchange on the Internet. Inspired by JSON-Storages the idea of a feature-rich
equivalent based on XML, XPath and XSLT was born. The focus should be on a
public, volatile and short-term data exchange for (static) web-applications and
IoT.

__Just exchange data without an own server landscape.__  
__Just exchange data without knowing and managing all clients.__

XML-Micro-Exchange is a volatile NoSQL stateless micro datasource for the
Internet. It is designed for easy communication and data exchange of
web-applications and for IoT or for other Internet-based modules and
components. The XML based datasource is volatile and lives through continuous
use and expires through inactivity. They are designed for active and near
real-time data exchange but not as a real-time capable long-term storage.
Compared to a JSON storage, this datasource supports more dynamics, partial
data access, data transformation, and volatile short-term storage.

__Why all this?__

- Static web-applications on different clients want to communicate with each
  other, e.g. for games, chats and collaboration.
- Smart sensors want to share their data and smart devices want to access this
  data and also exchange data with each other.
- Clients can establish dynamically volatile networks.

__In this communication are all participants.__  
__No one is a server or master, all are equal and no one has to know the other.__  
__All meet without obligation.__


# How to use this image

__Note:__ This quick guide always refers to the latest version, which includes
the version with the latest major and minor.

```
docker run -d -p 80:80/tcp --rm --name xmex seanox/xmex:latest
```

The Docker image is configured using the following environment variables, which
are passed as additional environment variables (`-e` / `--env`) when the Docker
image is started.

__XMEX_CONTAINER_MODE__  
Activates optimizations for use as a container.  
Supported values: `on`, `true`, `1`.  
Default: `on` (`off` in the on-premise installation)

__XMEX_DEBUG_MODE__  
Activates optimizations for debugging and testing.  
Supported values: `on`, `true`, `1`.  
Default: `off`

__XMEX_STORAGE_EXPIRATION__  
Maximum time of inactivity of the storage files in seconds. Without file access
during this time, the storage files are deleted.  
Default: `900` (15 min, 15 x 60 sec)

__XMEX_STORAGE_DIRECTORY__  
Directory of the data storage, which is configured with the required permissions
by the script at runtime.  
Default: `./data`

__XMEX_STORAGE_QUANTITY__  
Maximum number of files in data storage. Exceeding the limit causes the status
507 - Insufficient Storage.  
Default: `65535`

__XMEX_STORAGE_REVISION_TYPE__  
Defines the revision type. Supported values: `serial` (starting with 1),
`timestamp` (alphanumeric).  
Default: `timestamp`

__XMEX_STORAGE_SPACE__  
Maximum data size of files in data storage in bytes. The value also limits the
size of the requests(-body).  
Default: `262144` (256 kB, 256 x 1024 kB)

__XMEX_URI_XPATH_DELIMITER__  
Character or character sequence of the XPath delimiter in the URI. Changing this
value often also requires changes to the web server configuration.  
Default: `!`

## Links
- Service endpoint:  
  http://localhost/xmex!  
  Don't be surprised, without parameters the requests are responded to with
  status 400.
- OpenAPI GUI  
  http://localhost/openAPI.html
- OpenAPI YAML  
  http://localhost/openAPI.yaml
- Multiplayer Game Snake (little gimmick)  
  http://localhost/snake.yaml


# License

View [license information](
https://github.com/seanox/xml-micro-exchange-php/blob/master/LICENSE) for
the software contained in this image.

As with all Docker images, these likely also contain other software which may be
under other licenses (such as Bash, etc from the base distribution, along with
any direct or indirect dependencies of the primary software being contained).

As for any pre-built image usage, it is the image user's responsibility to
ensure that any use of this image complies with any relevant licenses for all
software contained within.
