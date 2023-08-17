
This directory contains classes defining services for this module. This will
include both custom services created by this module and extensions of
existing services (e.g. specialized logger classes).

There should not be subdirectories within this directory. If your service
has an administrative interface then that should be created separately as
a form, controller or basic twig page depending on its needs.

## What is a service (briefly)

You can think of a service as a class providing a specific service to other
classes within this or other extension modules. A class implementing an API
is a great example of a service. A service should only be used if you do not
expect or support a site using multiple implementations of the same service.
If you do, then you should create a plugin instead. That said, services are
more lightweight than plugins and should be used as much as possible.
