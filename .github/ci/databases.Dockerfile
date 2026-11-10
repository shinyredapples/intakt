# Never built: the persistence job in .github/workflows/ci.yml reads its database service images from these lines,
# and the docker entry of .github/dependabot.yml keeps them current. Stage names are the job's service names.
FROM mysql:8.4 AS mysql
FROM mariadb:11.4 AS mariadb
FROM postgres:17 AS postgresql
