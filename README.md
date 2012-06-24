This fork is a beginning for a fresh start sooner or later.

Prolog has very limited use but it can simplify some problems with few lines.
For example, you can avoid big boring sequences of if-else-switch in PHP 
to implement business intelligence with limited rules and predicates of Prolog.

My first goal is to validate, debug (if necessary) and port this js to php : goal achieved

Next goals :
* make a library : done
* re-routing output results in array : in progress 
* PhpUnit testing this library
* remove all notice and some strange features (removing NOTTHIS for example)
* namespacing the classes
* refactor the model to be more PHP-like and not js-like (remove some weird object)
* using getters and setters
* create a builder to easily construct atom & term without parsing
* make a bundle for symfony2 because it is the most advance and mature framework for PHP

