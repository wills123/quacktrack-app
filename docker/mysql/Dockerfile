ARG MYSQL_BASE_IMAGE=mysql:8.4.13@sha256:76f0358884cb92d9a086d24e5671c38048d3fef40f2588703d73eb55392d6b69
FROM ${MYSQL_BASE_IMAGE}
# The app uses mysql/mysqldump, never the bundled Python/JavaScript mysqlsh client.
RUN microdnf remove -y mysql-shell \
    && microdnf install -y util-linux \
    && microdnf update -y \
    && microdnf clean all \
    && command -v setpriv
RUN rm /usr/local/bin/gosu
COPY docker/mysql/drop-to-mysql.sh /usr/local/bin/gosu
RUN chmod 0755 /usr/local/bin/gosu
