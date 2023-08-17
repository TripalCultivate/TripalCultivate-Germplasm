
This directory contains classes which implement existing plugins provided
by Drupal core, Tripal core or other extension modules. To implement an
existing plugin you will create a directory within this directory as
specified for that specific plugin.

## What is a plugin (briefly)

You can think of a plugin as defining a type of functionality (e.g. Field, Importer)
that may/should have multiple implementations where a single site may want to use
multiple implementations. If it does not make sense for a site to use multiple
implementations of the same plugin then you should create a service instead.

Note: if you are implementing a plugin defined by another module, then ignore
the above definition as you cannot control the choice they made ;-p

## New Plugins defined by this module

New plugins defined by this module are a special case.
Convention will be explained using an example plugin named `MyFancyPlugin`.

1. Create a new directory in the base src directory for this module
   with the same name as your plugin. For example, `my_module/src/MyFancyPlugin`.
   This directory will contain the interface, annotation and base classes
   for your module but will not include an actual implementation. This
   directory should also contain the following sub-directories:
   `Interfaces`, `Annotation`, `PluginManagers` which contain the type
   of class specified by the name. All base classes for your plugin should
   be abstract class and in the `my_module/src/MyFancyPlugin` directory.
2. If your module also provides an implementation(s) of this plugin, then
   the class implementing it should be in this directory, `my_module/src/Plugins`
   the same as it would be for any module implementing your plugin.

## Tripal Fields

As described in the Tripal core documentation, Tripal/Chado field
implementations follow the same directory structure as all Drupal fields.
Specifically, you would create a `my_module/src/Field` directory that would
contain the following sub-directories: `FieldType`, `FieldWidget`, `FieldFormatter`.
For a single custom field you will have a class in each of these sub-directories.

Note: If you field includes a template file or anything else theme related,
then you will add a `theme` directory within `FieldFormatter` or `FieldWidget`
depending on whether the theme applied to the formatter or widget respectively.
All theme files should be prefixed with the name of the widget or formatter class
they apply to. Each theme file should be specific to a given class and not
reused for multiple classes. If you have more common theme rules, they should
be included in the `templates`, `css`, `js`, `images` directories at the
base of this module (i.e. `my_module/templates`).

## Tripal Importers

Tripal importers are plugin implementations. You will create a
`my_module/src/Plugin/TripalImporter` directory that will contain all
importers created by this module. If any of your importers require additional
files to theme the form, then you should also create a
`my_module/src/Plugin/TripalImporter/theme` directory. All theme files
should be prefixed with the name of the importer class.
