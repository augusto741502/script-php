pipeline {
  agent any
  stages {
    stage('verify version') {
      steps {
        sh 'php --version'
      }
    }
    stage('archivo') {
      steps {
        sh 'php archivo.php'
      }
    }
  }
}