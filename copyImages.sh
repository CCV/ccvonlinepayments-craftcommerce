rm -Rf $(pwd)/src/resources

mkdir $(pwd)/src/resources
mkdir $(pwd)/src/resources/methods

cp -Rf $(pwd)/vendor/ccv/images/*.png $(pwd)/src/resources
cp -Rf $(pwd)/vendor/ccv/images/methods/*.png $(pwd)/src/resources/methods

cp -Rf $(pwd)/vendor/ccv/images/logo.svg $(pwd)/src/icon.svg
